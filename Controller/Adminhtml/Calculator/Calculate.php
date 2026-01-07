<?php

namespace GardenLawn\Delivery\Controller\Adminhtml\Calculator;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use GardenLawn\Delivery\ViewModel\DistanceCalculator;

class Calculate extends Action
{
    const ADMIN_RESOURCE = 'GardenLawn_Delivery::calculator';

    private JsonFactory $resultJsonFactory;
    private DistanceCalculator $distanceCalculator;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        DistanceCalculator $distanceCalculator
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->distanceCalculator = $distanceCalculator;
    }

    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        if (!$this->distanceCalculator->isEnabled()) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('Module is disabled.')
            ]);
        }

        $origin = $this->getRequest()->getParam('origin');
        $destination = $this->getRequest()->getParam('destination');
        $quantity = (float)$this->getRequest()->getParam('quantity', 0);

        if (!$origin || !$destination) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('Please provide both origin and destination addresses.')
            ]);
        }

        try {
            // Calculate distance from default origin (for display purposes)
            $result = $this->distanceCalculator->getDistance($origin, $destination);

            if (isset($result['error'])) {
                $errorMessage = $result['error'];
                $rawJson = isset($result['raw_json']) ? $result['raw_json'] : null;

                $responseData = [
                    'success' => false,
                    'message' => __('API Error: %1', $errorMessage)
                ];

                if ($rawJson) {
                    $responseData['raw_json'] = json_encode(json_decode($rawJson), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                }

                return $resultJson->setData($responseData);
            }

            if ($result && isset($result['element'])) {
                $element = $result['element'];
                $rawJson = $result['raw_json'];

                // Extract distance value in km
                $distanceValue = $element['element']['distance']['value'] ?? $element['distance']['value']; // Handle potential structure diffs
                $distanceKm = $distanceValue / 1000;

                // Calculate shipping costs using ViewModel logic (handles specific origins)
                $shippingCosts = [];
                if ($quantity > 0) {
                    $rawCosts = $this->distanceCalculator->calculateShippingCosts($distanceKm, $quantity, $destination);

                    // Format prices for admin display
                    foreach ($rawCosts as $cost) {
                        $formattedPrice = $this->_objectManager->get('Magento\Framework\Pricing\Helper\Data')->currency($cost['price'], true, false);
                        $shippingCosts[] = [
                            'method' => $cost['method'],
                            'price' => $formattedPrice,
                            'distance' => isset($cost['distance']) ? round($cost['distance'], 1) . ' km' : null
                        ];
                    }
                }

                // Pretty print the JSON
                $prettyJson = json_encode(json_decode($rawJson), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                // Handle structure differences if any (Google vs HERE response structure in ViewModel might vary slightly)
                $distText = $element['distance']['text'] ?? $element['element']['distance']['text'] ?? '';
                $durText = $element['duration']['text'] ?? $element['element']['duration']['text'] ?? '';
                $depTime = $element['departure_time'] ?? '';
                $arrTime = $element['arrival_time'] ?? '';

                return $resultJson->setData([
                    'success' => true,
                    'distance' => $distText,
                    'duration' => $durText,
                    'departure_time' => $depTime,
                    'arrival_time' => $arrTime,
                    'raw_json' => $prettyJson,
                    'shipping_costs' => $shippingCosts
                ]);
            } else {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('Could not calculate distance. Unknown error.')
                ]);
            }
        } catch (\Exception $e) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('An error occurred: %1', $e->getMessage())
            ]);
        }
    }
}
