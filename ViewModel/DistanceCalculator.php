<?php

namespace GardenLawn\Delivery\ViewModel;

use GardenLawn\Delivery\Helper\Config;
use GardenLawn\Delivery\Model\Carrier\CourierShipping;
use GardenLawn\Delivery\Model\Carrier\DirectForklift;
use GardenLawn\Delivery\Model\Carrier\DirectLift;
use GardenLawn\Delivery\Model\Carrier\DirectNoLift;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\HTTP\Client\Curl;

class DistanceCalculator implements ArgumentInterface
{
    private Config $config;
    private Curl $curl;
    private CourierShipping $courierShipping;
    private DirectNoLift $directNoLift;
    private DirectLift $directLift;
    private DirectForklift $directForklift;

    // Cache for distances within request to avoid duplicate API calls
    private array $distanceCache = [];

    public function __construct(
        Config $config,
        Curl $curl,
        CourierShipping $courierShipping,
        DirectNoLift $directNoLift,
        DirectLift $directLift,
        DirectForklift $directForklift
    ) {
        $this->config = $config;
        $this->curl = $curl;
        $this->courierShipping = $courierShipping;
        $this->directNoLift = $directNoLift;
        $this->directLift = $directLift;
        $this->directForklift = $directForklift;
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
        $cacheKey = md5($origin . '|' . $destination);
        if (isset($this->distanceCache[$cacheKey])) {
            return $this->distanceCache[$cacheKey];
        }

        $provider = $this->config->getProvider();
        $result = ($provider === 'here')
            ? $this->getHereDistance($origin, $destination)
            : $this->getGoogleDistance($origin, $destination);

        $this->distanceCache[$cacheKey] = $result;
        return $result;
    }

    public function calculateShippingCosts(float $defaultDistanceKm, float $qty, string $destination): array
    {
        $costs = [];
        $defaultOrigin = $this->getDefaultOrigin();

        // 1. Courier Shipping (Pallet) - Distance independent (usually)
        if ($this->courierShipping->getConfigFlag('active')) {
            $price = $this->courierShipping->calculatePrice($qty);
            if ($price > 0) {
                $costs[] = [
                    'method' => $this->courierShipping->getConfigData('name'),
                    'description' => $this->courierShipping->getConfigData('description'),
                    'price' => $price
                ];
            }
        }

        // Helper function to process Direct methods
        $processDirectMethod = function ($method) use ($qty, $destination, $defaultOrigin, $defaultDistanceKm) {
            if (!$method->getConfigFlag('active')) {
                return null;
            }

            $methodOrigin = $method->getOrigin();
            $distanceKm = $defaultDistanceKm;

            // If method has a specific origin different from default, recalculate distance
            if ($methodOrigin && $methodOrigin !== $defaultOrigin) {
                $result = $this->getDistance($methodOrigin, $destination);
                if (isset($result['element']['distance']['value'])) {
                    $distanceKm = $result['element']['distance']['value'] / 1000;
                } else {
                    // If distance calculation fails for specific origin, skip this method
                    return null;
                }
            }

            $price = $method->calculatePrice($distanceKm, $qty);
            if ($price > 0) {
                return [
                    'method' => $method->getConfigData('name'),
                    'description' => $method->getConfigData('description'),
                    'price' => $price,
                    'distance' => $distanceKm // Optional: return specific distance for debug/info
                ];
            }
            return null;
        };

        // 2. Direct No Lift
        $cost = $processDirectMethod($this->directNoLift);
        if ($cost) $costs[] = $cost;

        // 3. Direct Lift
        $cost = $processDirectMethod($this->directLift);
        if ($cost) $costs[] = $cost;

        // 4. Direct Forklift
        $cost = $processDirectMethod($this->directForklift);
        if ($cost) $costs[] = $cost;

        return $costs;
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
            // Use a new Curl instance to avoid state issues
            $curl = new \Magento\Framework\HTTP\Client\Curl();
            $curl->get($url);
            $responseBody = $curl->getBody();
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
                        'departure_time' => $departureTime->format('d.m.Y H:i'),
                        'arrival_time' => $arrivalTime->format('d.m.Y H:i')
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
                        'departure_time' => $departureTime->format('d.m.Y H:i'),
                        'arrival_time' => $arrivalTime->format('d.m.Y H:i')
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
