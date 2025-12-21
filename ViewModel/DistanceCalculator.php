<?php

namespace GardenLawn\Delivery\ViewModel;

use GardenLawn\Delivery\Helper\Config;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\HTTP\Client\Curl;

class DistanceCalculator implements ArgumentInterface
{
    private Config $config;
    private Curl $curl;

    public function __construct(Config $config, Curl $curl)
    {
        $this->config = $config;
        $this->curl = $curl;
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function getDefaultOrigin(): ?string
    {
        return $this->config->getWarehouseOrigin();
    }

    public function getDistance(string $origin, string $destination): ?array
    {
        $provider = $this->config->getProvider();

        if ($provider === 'here') {
            return $this->getHereDistance($origin, $destination);
        }

        return $this->getGoogleDistance($origin, $destination);
    }

    private function getGoogleDistance(string $origin, string $destination): ?array
    {
        $apiKey = $this->config->getGoogleApiKey();

        if (!$apiKey || !$origin || !$destination) {
            return ['error' => 'Missing API Key or Addresses'];
        }

        $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins="
            . urlencode($origin) . "&destinations=" . urlencode($destination)
            . "&key=" . $apiKey;

        try {
            $this->curl->get($url);
            $responseBody = $this->curl->getBody();
            $result = json_decode($responseBody, true);

            if (isset($result['rows'][0]['elements'][0]['distance'])) {
                $element = $result['rows'][0]['elements'][0];

                // Calculate Times
                $durationSeconds = $element['duration']['value'];
                $departureTime = new \DateTime();
                $arrivalTime = (clone $departureTime)->modify("+{$durationSeconds} seconds");

                return [
                    'element' => [
                        'distance' => $element['distance'],
                        'duration' => $element['duration'],
                        'departure_time' => $departureTime->format('Y-m-d H:i'),
                        'arrival_time' => $arrivalTime->format('Y-m-d H:i')
                    ],
                    'raw_json' => $responseBody
                ];
            }

            // Return error from Google if available
            if (isset($result['error_message'])) {
                return ['error' => $result['error_message'], 'raw_json' => $responseBody];
            }

        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }

        return ['error' => 'Unknown Google API error', 'raw_json' => $responseBody ?? null];
    }

    private function getHereDistance(string $origin, string $destination): ?array
    {
        $apiKey = $this->config->getHereApiKey();

        if (!$apiKey || !$origin || !$destination) {
            return ['error' => 'Missing HERE API Key or Addresses'];
        }

        try {
            // 1. Geocode Origin
            $originCoords = $this->getHereCoordinates($origin, $apiKey);
            if (isset($originCoords['error'])) {
                return ['error' => 'Origin Geocoding Error: ' . $originCoords['error']];
            }

            // 2. Geocode Destination
            $destCoords = $this->getHereCoordinates($destination, $apiKey);
            if (isset($destCoords['error'])) {
                return ['error' => 'Destination Geocoding Error: ' . $destCoords['error']];
            }

            // 3. Build Route URL with Truck Parameters
            $params = [
                'transportMode' => 'truck',
                'origin' => $originCoords['lat'] . "," . $originCoords['lng'],
                'destination' => $destCoords['lat'] . "," . $destCoords['lng'],
                'return' => 'summary',
                'apiKey' => $apiKey
            ];

            $truckParams = $this->config->getTruckParameters();

            // Convert meters to centimeters for HERE API (must be integer)
            if (!empty($truckParams['height'])) {
                $params['vehicle[height]'] = (int)($truckParams['height'] * 100);
            }
            if (!empty($truckParams['width'])) {
                $params['vehicle[width]'] = (int)($truckParams['width'] * 100);
            }
            if (!empty($truckParams['length'])) {
                $params['vehicle[length]'] = (int)($truckParams['length'] * 100);
            }

            // Weights are already in kg, which is correct
            if (!empty($truckParams['grossWeight'])) {
                $params['vehicle[grossWeight]'] = (int)$truckParams['grossWeight'];
            }
            if (!empty($truckParams['weightPerAxle'])) {
                $params['vehicle[weightPerAxle]'] = (int)$truckParams['weightPerAxle'];
            }
            if (!empty($truckParams['axleCount'])) {
                $params['vehicle[axleCount]'] = (int)$truckParams['axleCount'];
            }

            // vehicle[type] is problematic in v8 routing API and often not needed if dimensions are provided.
            // We skip it to avoid "Invalid value" errors.
            /*
            if (!empty($truckParams['type'])) {
                $params['vehicle[type]'] = $truckParams['type'];
            }
            */

            if (!empty($truckParams['hazardousGoods'])) {
                $params['shippedHazardousGoods'] = $truckParams['hazardousGoods'];
            }
            if (!empty($truckParams['avoid'])) {
                $params['avoid[features]'] = $truckParams['avoid'];
            }

            // Custom query building to handle brackets and commas correctly for HERE API
            $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $queryString = str_replace(['%2C', '%5B', '%5D'], [',', '[', ']'], $queryString);

            $url = "https://router.hereapi.com/v8/routes?" . $queryString;

            // Use a fresh Curl instance for the route request to ensure clean state
            $curl = new \Magento\Framework\HTTP\Client\Curl();
            $curl->get($url);
            $responseBody = $curl->getBody();
            $result = json_decode($responseBody, true);

            if (isset($result['routes'][0]['sections'][0]['summary'])) {
                $summary = $result['routes'][0]['sections'][0]['summary'];

                // Convert meters to km and seconds to readable format
                $distanceText = round($summary['length'] / 1000, 1) . ' km';
                $durationText = round($summary['duration'] / 60) . ' mins';

                // Calculate Times
                $durationSeconds = $summary['duration'];
                $departureTime = new \DateTime();
                $arrivalTime = (clone $departureTime)->modify("+{$durationSeconds} seconds");

                return [
                    'element' => [
                        'distance' => ['text' => $distanceText, 'value' => $summary['length']],
                        'duration' => ['text' => $durationText, 'value' => $summary['duration']],
                        'departure_time' => $departureTime->format('Y-m-d H:i'),
                        'arrival_time' => $arrivalTime->format('Y-m-d H:i')
                    ],
                    'raw_json' => $responseBody
                ];
            }

            // Handle HERE API Errors
            if (isset($result['title'])) {
                return ['error' => $result['title'] . (isset($result['cause']) ? ': ' . $result['cause'] : ''), 'raw_json' => $responseBody];
            }

        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }

        return ['error' => 'Unknown HERE API error', 'raw_json' => $responseBody ?? null];
    }

    private function getHereCoordinates(string $address, string $apiKey): ?array
    {
        $url = "https://geocode.search.hereapi.com/v1/geocode?q=" . urlencode($address) . "&apiKey=" . $apiKey;

        try {
            $curl = new \Magento\Framework\HTTP\Client\Curl();
            $curl->get($url);
            $responseBody = $curl->getBody();
            $response = json_decode($responseBody, true);

            if (isset($response['items'][0]['position'])) {
                return $response['items'][0]['position'];
            }

            if (isset($response['title'])) {
                return ['error' => $response['title']];
            }

            if (empty($response['items'])) {
                return ['error' => 'Address not found'];
            }
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }

        return ['error' => 'Unknown Geocoding error'];
    }
}
