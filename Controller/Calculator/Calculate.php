<?php

namespace GardenLawn\Delivery\Controller\Calculator;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use GardenLawn\Delivery\ViewModel\DistanceCalculator;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;

class Calculate implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private JsonFactory $resultJsonFactory;
    private DistanceCalculator $distanceCalculator;
    private \Magento\Framework\Pricing\Helper\Data $pricingHelper;
    private RequestInterface $request;
    private ProductRepositoryInterface $productRepository;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        DistanceCalculator $distanceCalculator,
        \Magento\Framework\Pricing\Helper\Data $pricingHelper,
        RequestInterface $request,
        ProductRepositoryInterface $productRepository
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->distanceCalculator = $distanceCalculator;
        $this->pricingHelper = $pricingHelper;
        $this->request = $request;
        $this->productRepository = $productRepository;
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
        $productId = $this->request->getParam('product_id');

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

                // Load product if ID is provided
                $product = null;
                if ($productId) {
                    try {
                        $product = $this->productRepository->getById($productId);
                    } catch (\Exception $e) {
                        // Product not found, continue without promotions
                    }
                }

                // Extract postcode from destination (assuming format "POSTCODE, COUNTRY" or just "POSTCODE")
                $postcode = explode(',', $destination)[0];
                $postcode = trim($postcode);

                // Calculate shipping costs
                $shippingCosts = [];
                if ($qty > 0) {
                    $rawCosts = $this->distanceCalculator->calculateShippingCosts($distanceKm, $qty, $destination);

                    foreach ($rawCosts as $cost) {
                        $promotionMessage = null;
                        if ($product) {
                            // Pass product, method code, qty (ignored but passed), and postcode
                            $promotionMessage = $this->distanceCalculator->getPromotionMessage($product, $cost['code'], $qty, $postcode);
                        }

                        $shippingCosts[] = [
                            'code' => $cost['code'], // Ensure code is passed
                            'method' => $cost['method'],
                            'carrier_title' => $cost['carrier_title'] ?? '',
                            'description' => $cost['description'] ?? '',
                            'price' => $cost['price'],
                            'formatted_price' => $this->pricingHelper->currency($cost['price'], true, false),
                            'formatted_price_net' => $cost['formatted_price_net'] ?? null,
                            'formatted_price_gross' => $cost['formatted_price_gross'] ?? null,
                            'distance' => isset($cost['distance']) ? round($cost['distance'], 1) . ' km' : null,
                            'source' => $cost['source'] ?? null,
                            'price_details' => $cost['price_details'] ?? null,
                            'promotion_message' => $promotionMessage
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
