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

    public function getDistance(string $destination): ?array
    {
        $apiKey = $this->config->getApiKey();
        $origin = $this->config->getWarehouseOrigin();

        if (!$apiKey || !$origin || !$destination) {
            return null;
        }

        $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins="
            . urlencode($origin) . "&destinations=" . urlencode($destination)
            . "&key=" . $apiKey;

        try {
            $this->curl->get($url);
            $result = json_decode($this->curl->getBody(), true);

            if (isset($result['rows'][0]['elements'][0]['distance'])) {
                return $result['rows'][0]['elements'][0];
            }
        } catch (\Exception $e) {
            // Log error if needed
        }

        return null;
    }
}
