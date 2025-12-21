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
        $apiKey = $this->config->getApiKey();

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
                return [
                    'element' => $result['rows'][0]['elements'][0],
                    'raw_json' => $responseBody
                ];
            }
        } catch (\Exception $e) {
            // Log error if needed
        }

        return null;
    }
}
