<?php

namespace GardenLawn\Delivery\Controller\Calculator;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use GardenLawn\Delivery\ViewModel\DistanceCalculator;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;

class Calculate implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private JsonFactory $resultJsonFactory;
    private DistanceCalculator $distanceCalculator;
    private \Magento\Framework\Pricing\Helper\Data $pricingHelper;
    private RequestInterface $request;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        DistanceCalculator $distanceCalculator,
        \Magento\Framework\Pricing\Helper\Data $pricingHelper,
        RequestInterface $request
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->distanceCalculator = $distanceCalculator;
        $this->pricingHelper = $pricingHelper;
        $this->request = $request;
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

        $destination = $this->request->getParam('destination');
        $qty = (float)$this->request->getParam('qty', 0);

        // Origin is handled by ViewModel (default or specific per method)
        $origin = $this->distanceCalculator->getDefaultOrigin();

        if (!$destination || !$origin) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('Please provide destination address.')
            ]);
        }

        try {
            // Calculate distance from default origin (for display)
            $result = $this->distanceCalculator->getDistance($origin, $destination);

            if (isset($result['error'])) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => $result['error']
                ]);
            }

            if ($result && isset($result['element'])) {
                $element = $result['element'];

                // Extract distance value in km
                $distanceValue = $element['distance']['value']; // meters
                $distanceKm = $distanceValue / 1000;

                // Calculate shipping costs
                $shippingCosts = [];
                if ($qty > 0) {
                    $rawCosts = $this->distanceCalculator->calculateShippingCosts($distanceKm, $qty, $destination);

                    foreach ($rawCosts as $cost) {
                        $shippingCosts[] = [
                            'method' => $cost['method'],
                            'description' => $cost['description'] ?? '',
                            'price' => $cost['price'],
                            'formatted_price' => $this->pricingHelper->currency($cost['price'], true, false),
                            'distance' => isset($cost['distance']) ? round($cost['distance'], 1) . ' km' : null
                        ];
                    }
                }

                return $resultJson->setData([
                    'success' => true,
                    'distance' => $element['distance']['text'],
                    'duration' => $element['duration']['text'],
                    'shipping_costs' => $shippingCosts
                ]);
            }

            return $resultJson->setData([
                'success' => false,
                'message' => __('Could not calculate distance.')
            ]);

        } catch (\Exception $e) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('An error occurred.')
            ]);
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
