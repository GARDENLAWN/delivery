<?php

namespace GardenLawn\Delivery\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class DistanceService
{
    private const GOOGLE_MAPS_API_URL = 'https://maps.googleapis.com/maps/api/distancematrix/json';
    private const GOOGLE_GEOCODING_API_URL = 'https://maps.googleapis.com/maps/api/geocode/json';
    private const HERE_API_URL = 'https://router.hereapi.com/v8/routes';

    protected ScopeConfigInterface $scopeConfig;
    protected Curl $curl;
    protected LoggerInterface $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Curl $curl,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->curl = $curl;
        $this->logger = $logger;
    }

    /**
     * Get distance between two points using configured provider
     *
     * @param string $origin
     * @param string $destination
     * @return float Distance in kilometers
     */
    public function getDistance(string $origin, string $destination): float
    {
        $provider = $this->scopeConfig->getValue('delivery/api_provider/provider');

        if ($provider === 'here') {
            return $this->getDistanceFromHere($origin, $destination);
        }

        return $this->getDistanceFromGoogle($origin, $destination);
    }

    /**
     * Get coordinates for an address using configured provider
     *
     * @param string $address
     * @return array|null ['lat' => float, 'lng' => float] or null
     */
    public function getCoordinates(string $address): ?array
    {
        $provider = $this->scopeConfig->getValue('delivery/api_provider/provider');

        if ($provider === 'here') {
            $coordsStr = $this->geocodeAddressHere($address);
            if ($coordsStr) {
                list($lat, $lng) = explode(',', $coordsStr);
                return ['lat' => (float)$lat, 'lng' => (float)$lng];
            }
            return null;
        }

        return $this->geocodeAddressGoogle($address);
    }

    /**
     * Get distance using Google Maps Distance Matrix API
     *
     * @param string $origin
     * @param string $destination
     * @return float
     */
    private function getDistanceFromGoogle(string $origin, string $destination): float
    {
        try {
            $apiKey = $this->scopeConfig->getValue('delivery/api_provider/google_maps_api_key');
            if (!$apiKey) {
                $this->logger->warning('DistanceService: Google Maps API key is missing');
                return 0.0;
            }

            $url = self::GOOGLE_MAPS_API_URL . '?' . http_build_query([
                'origins' => $origin,
                'destinations' => $destination,
                'key' => $apiKey
            ]);

            $this->curl->get($url);
            $response = $this->curl->getBody();
            $data = json_decode($response, true);

            if (!$data || !isset($data['rows'][0]['elements'][0]['status'])) {
                $this->logger->error('DistanceService: Invalid Google Maps API response');
                return 0.0;
            }

            if ($data['rows'][0]['elements'][0]['status'] !== 'OK') {
                $this->logger->warning('DistanceService: Google Maps distance calculation failed - ' . ($data['rows'][0]['elements'][0]['status'] ?? 'unknown'));
                return 0.0;
            }

            return floatval($data['rows'][0]['elements'][0]['distance']['value'] / 1000.0);
        } catch (\Exception $e) {
            $this->logger->error('DistanceService Google Maps: ' . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Geocode address using Google Maps Geocoding API
     *
     * @param string $address
     * @return array|null
     */
    private function geocodeAddressGoogle(string $address): ?array
    {
        try {
            $apiKey = $this->scopeConfig->getValue('delivery/api_provider/google_maps_api_key');
            if (!$apiKey) {
                return null;
            }

            $url = self::GOOGLE_GEOCODING_API_URL . '?' . http_build_query([
                'address' => $address,
                'key' => $apiKey
            ]);

            $this->curl->get($url);
            $response = $this->curl->getBody();
            $data = json_decode($response, true);

            if (!$data || !isset($data['status']) || $data['status'] !== 'OK') {
                return null;
            }

            if (isset($data['results'][0]['geometry']['location'])) {
                $loc = $data['results'][0]['geometry']['location'];
                return ['lat' => (float)$loc['lat'], 'lng' => (float)$loc['lng']];
            }
        } catch (\Exception $e) {
            $this->logger->error('DistanceService Google Geocode: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Get distance using HERE Routing API v8
     *
     * @param string $origin
     * @param string $destination
     * @return float
     */
    private function getDistanceFromHere(string $origin, string $destination): float
    {
        try {
            $apiKey = $this->scopeConfig->getValue('delivery/api_provider/here_api_key');
            if (!$apiKey) {
                $this->logger->warning('DistanceService: HERE API key is missing');
                return 0.0;
            }

            // Geocode addresses to coordinates
            $originCoords = $this->geocodeAddressHere($origin);
            $destCoords = $this->geocodeAddressHere($destination);

            if (!$originCoords || !$destCoords) {
                $this->logger->warning('DistanceService: Failed to geocode addresses');
                return 0.0;
            }

            // Build truck parameters
            $truckParams = $this->buildTruckParameters();

            $params = [
                'transportMode' => 'truck',
                'origin' => $originCoords,
                'destination' => $destCoords,
                'return' => 'summary',
                'apiKey' => $apiKey
            ];

            // Merge truck parameters
            $params = array_merge($params, $truckParams);

            // Custom query building to handle brackets and commas correctly for HERE API
            $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $queryString = str_replace(['%2C', '%5B', '%5D'], [',', '[', ']'], $queryString);

            $url = self::HERE_API_URL . '?' . $queryString;

            // Use a fresh Curl instance for the route request to ensure clean state
            $curl = new \Magento\Framework\HTTP\Client\Curl();
            $curl->get($url);
            $response = $curl->getBody();
            $data = json_decode($response, true);

            if (!$data || !isset($data['routes'][0]['sections'][0]['summary']['length'])) {
                $this->logger->error('DistanceService: Invalid HERE API response - ' . ($response ?? 'empty'));
                return 0.0;
            }

            // HERE returns distance in meters
            return floatval($data['routes'][0]['sections'][0]['summary']['length'] / 1000.0);
        } catch (\Exception $e) {
            $this->logger->error('DistanceService HERE: ' . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Geocode address to coordinates using HERE Geocoding API
     *
     * @param string $address
     * @return string|null Format: "lat,lng"
     */
    private function geocodeAddressHere(string $address): ?string
    {
        try {
            $apiKey = $this->scopeConfig->getValue('delivery/api_provider/here_api_key');
            $url = 'https://geocode.search.hereapi.com/v1/geocode?' . http_build_query([
                'q' => $address,
                'apiKey' => $apiKey
            ]);

            $this->curl->get($url);
            $response = $this->curl->getBody();
            $data = json_decode($response, true);

            if (!$data || !isset($data['items'][0]['position'])) {
                $this->logger->warning('DistanceService: Failed to geocode address: ' . $address);
                return null;
            }

            $position = $data['items'][0]['position'];
            return $position['lat'] . ',' . $position['lng'];
        } catch (\Exception $e) {
            $this->logger->error('DistanceService geocode: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Build truck parameters from configuration
     *
     * @return array
     */
    private function buildTruckParameters(): array
    {
        $params = [];

        // Vehicle dimensions - HERE API expects centimeters for dimensions
        $height = $this->scopeConfig->getValue('delivery/truck_settings/vehicle_height');
        if ($height) {
            $params['vehicle[height]'] = (int)($height * 100);
        }

        $width = $this->scopeConfig->getValue('delivery/truck_settings/vehicle_width');
        if ($width) {
            $params['vehicle[width]'] = (int)($width * 100);
        }

        $length = $this->scopeConfig->getValue('delivery/truck_settings/vehicle_length');
        if ($length) {
            $params['vehicle[length]'] = (int)($length * 100);
        }

        // Vehicle weight - HERE API expects kg
        $weight = $this->scopeConfig->getValue('delivery/truck_settings/vehicle_weight');
        if ($weight) {
            $params['vehicle[grossWeight]'] = (int)$weight;
        }

        $axleWeight = $this->scopeConfig->getValue('delivery/truck_settings/vehicle_axle_weight');
        if ($axleWeight) {
            $params['vehicle[weightPerAxle]'] = (int)$axleWeight;
        }

        $axleCount = $this->scopeConfig->getValue('delivery/truck_settings/vehicle_axle_count');
        if ($axleCount) {
            $params['vehicle[axleCount]'] = (int)$axleCount;
        }

        // Vehicle type
        /*
        $vehicleType = $this->scopeConfig->getValue('delivery/truck_settings/vehicle_type');
        if ($vehicleType) {
            $params['vehicle[type]'] = $vehicleType;
        }
        */

        // Hazardous goods
        $hazardousGoods = $this->scopeConfig->getValue('delivery/truck_settings/hazardous_goods');
        if ($hazardousGoods) {
            $goods = explode(',', $hazardousGoods);
            // HERE API expects comma separated list for shippedHazardousGoods
            $params['shippedHazardousGoods'] = implode(',', array_map('trim', $goods));
        }

        // Avoid features
        $avoidFeatures = $this->scopeConfig->getValue('delivery/truck_settings/avoid_features');
        if ($avoidFeatures) {
            $features = explode(',', $avoidFeatures);
            // HERE API expects comma separated list for avoid[features]
            $params['avoid[features]'] = implode(',', array_map('trim', $features));
        }

        return $params;
    }

    /**
     * Get distance for multiple waypoints
     *
     * @param array $points Array of addresses
     * @return float Total distance in kilometers
     */
    public function getDistanceForPoints(array $points): float
    {
        $distance = 0.0;

        for ($i = 0; $i < count($points) - 1; $i++) {
            $distance += $this->getDistance($points[$i], $points[$i + 1]);
        }

        return $distance;
    }
}
