<?php

namespace GardenLawn\Delivery\Controller\Adminhtml\Calculator;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use GardenLawn\Delivery\ViewModel\DistanceCalculator;
use GardenLawn\Delivery\Model\Carrier\CourierShipping;
use GardenLawn\Delivery\Model\Carrier\DirectNoLift;
use GardenLawn\Delivery\Model\Carrier\DirectLift;
use GardenLawn\Delivery\Model\Carrier\DirectForklift;

class Calculate extends Action
{
    const ADMIN_RESOURCE = 'GardenLawn_Delivery::calculator';

    private JsonFactory $resultJsonFactory;
    private DistanceCalculator $distanceCalculator;
    private CourierShipping $courierShipping;
    private DirectNoLift $directNoLift;
    private DirectLift $directLift;
    private DirectForklift $directForklift;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        DistanceCalculator $distanceCalculator,
        CourierShipping $courierShipping,
        DirectNoLift $directNoLift,
        DirectLift $directLift,
        DirectForklift $directForklift
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->distanceCalculator = $distanceCalculator;
        $this->courierShipping = $courierShipping;
        $this->directNoLift = $directNoLift;
        $this->directLift = $directLift;
        $this->directForklift = $directForklift;
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
                    // 1. Courier Shipping (Pallet)
                    if ($this->courierShipping->getConfigFlag('active')) {
                        $price = $this->courierShipping->calculatePrice($quantity);
                        if ($price > 0) {
                            $shippingCosts[] = [
                                'method' => $this->courierShipping->getConfigData('name'),
                                'price' => $this->_objectManager->get('Magento\Framework\Pricing\Helper\Data')->currency($price, true, false)
                            ];
                        }
                    }

                    // 2. Direct No Lift
                    if ($this->directNoLift->getConfigFlag('active')) {
                        $price = $this->directNoLift->calculatePrice($distanceKm, $quantity);
                        if ($price > 0) {
                            $shippingCosts[] = [
                                'method' => $this->directNoLift->getConfigData('name'),
                                'price' => $this->_objectManager->get('Magento\Framework\Pricing\Helper\Data')->currency($price, true, false)
                            ];
                        }
                    }

                    // 3. Direct Lift
                    if ($this->directLift->getConfigFlag('active')) {
                        $price = $this->directLift->calculatePrice($distanceKm, $quantity);
                        if ($price > 0) {
                            $shippingCosts[] = [
                                'method' => $this->directLift->getConfigData('name'),
                                'price' => $this->_objectManager->get('Magento\Framework\Pricing\Helper\Data')->currency($price, true, false)
                            ];
                        }
                    }

                    // 4. Direct Forklift
                    if ($this->directForklift->getConfigFlag('active')) {
                        $price = $this->directForklift->calculatePrice($distanceKm, $quantity);
                        if ($price > 0) {
                            $shippingCosts[] = [
                                'method' => $this->directForklift->getConfigData('name'),
                                'price' => $this->_objectManager->get('Magento\Framework\Pricing\Helper\Data')->currency($price, true, false)
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
