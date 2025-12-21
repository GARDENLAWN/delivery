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
            return null;
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
        } catch (\Exception $e) {
            // Log error
        }

        return null;
    }

    private function getHereDistance(string $origin, string $destination): ?array
    {
        $apiKey = $this->config->getHereApiKey();

        if (!$apiKey || !$origin || !$destination) {
            return null;
        }

        try {
            // 1. Geocode Origin
            $originCoords = $this->getHereCoordinates($origin, $apiKey);
            if (!$originCoords) {
                return null;
            }

            // 2. Geocode Destination
            $destCoords = $this->getHereCoordinates($destination, $apiKey);
            if (!$destCoords) {
                return null;
            }

            // 3. Calculate Route (using 'truck' mode)
            $url = "https://router.hereapi.com/v8/routes?transportMode=truck" .
                   "&origin=" . $originCoords['lat'] . "," . $originCoords['lng'] .
                   "&destination=" . $destCoords['lat'] . "," . $destCoords['lng'] .
                   "&return=summary" .
                   "&apiKey=" . $apiKey;

            $this->curl->get($url);
            $responseBody = $this->curl->getBody();
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

        } catch (\Exception $e) {
            // Log error
        }

        return null;
    }

    private function getHereCoordinates(string $address, string $apiKey): ?array
    {
        $url = "https://geocode.search.hereapi.com/v1/geocode?q=" . urlencode($address) . "&apiKey=" . $apiKey;

        // Create a new Curl instance for nested calls to avoid conflicts
        $curl = new \Magento\Framework\HTTP\Client\Curl();
        $curl->get($url);
        $response = json_decode($curl->getBody(), true);

        if (isset($response['items'][0]['position'])) {
            return $response['items'][0]['position'];
        }

        return null;
    }
}
