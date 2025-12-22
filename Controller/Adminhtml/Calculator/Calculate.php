<?php

namespace GardenLawn\Delivery\Controller\Adminhtml\Calculator;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use GardenLawn\Delivery\ViewModel\DistanceCalculator;
use GardenLawn\Delivery\Model\Carrier\DistanceShipping;
use GardenLawn\Delivery\Model\Carrier\CourierShipping;
use GardenLawn\Delivery\Model\Carrier\CourierWithElevatorShipping;

class Calculate extends Action
{
    const ADMIN_RESOURCE = 'GardenLawn_Delivery::calculator';

    private JsonFactory $resultJsonFactory;
    private DistanceCalculator $distanceCalculator;
    private DistanceShipping $distanceShipping;
    private CourierShipping $courierShipping;
    private CourierWithElevatorShipping $courierWithElevatorShipping;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        DistanceCalculator $distanceCalculator,
        DistanceShipping $distanceShipping,
        CourierShipping $courierShipping,
        CourierWithElevatorShipping $courierWithElevatorShipping
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->distanceCalculator = $distanceCalculator;
        $this->distanceShipping = $distanceShipping;
        $this->courierShipping = $courierShipping;
        $this->courierWithElevatorShipping = $courierWithElevatorShipping;
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
                $distanceValue = $element['distance']['value']; // meters
                $distanceKm = $distanceValue / 1000;

                // Calculate shipping costs
                $shippingCosts = [];

                if ($quantity > 0) {
                    // Distance Shipping
                    if ($this->distanceShipping->getConfigFlag('active')) {
                        $price = $this->distanceShipping->calculatePrice($distanceKm, $quantity);
                        if ($price > 0) {
                            $shippingCosts[] = [
                                'method' => $this->distanceShipping->getConfigData('name'),
                                'price' => $price
                            ];
                        }
                    }

                    // Courier Shipping
                    if ($this->courierShipping->getConfigFlag('active')) {
                        $price = $this->courierShipping->calculatePrice($quantity);
                        if ($price > 0) {
                            $shippingCosts[] = [
                                'method' => $this->courierShipping->getConfigData('name'),
                                'price' => $price
                            ];
                        }
                    }

                    // Courier With Elevator Shipping
                    if ($this->courierWithElevatorShipping->getConfigFlag('active')) {
                        $price = $this->courierWithElevatorShipping->calculatePrice($distanceKm, $quantity);
                        if ($price > 0) {
                            $shippingCosts[] = [
                                'method' => $this->courierWithElevatorShipping->getConfigData('name'),
                                'price' => $price
                            ];
                        }
                    }
                }

                // Pretty print the JSON
                $prettyJson = json_encode(json_decode($rawJson), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                return $resultJson->setData([
                    'success' => true,
                    'distance' => $element['distance']['text'],
                    'duration' => $element['duration']['text'],
                    'departure_time' => $element['departure_time'],
                    'arrival_time' => $element['arrival_time'],
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
