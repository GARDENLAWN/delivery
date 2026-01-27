<?php

namespace GardenLawn\Delivery\ViewModel;

use GardenLawn\Delivery\Helper\Config;
use GardenLawn\Delivery\Model\Carrier\CourierShipping;
use GardenLawn\Delivery\Model\Carrier\DirectForklift;
use GardenLawn\Delivery\Model\Carrier\DirectLift;
use GardenLawn\Delivery\Model\Carrier\DirectNoLift;
use GardenLawn\Delivery\Model\Carrier\DistanceShipping;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\Quote\Model\Quote\AddressFactory;
use Magento\Quote\Model\Quote\ItemFactory;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\Calculation;

class DistanceCalculator implements ArgumentInterface
{
    private Config $config;
    private Curl $curl;
    private CourierShipping $courierShipping;
    private DirectNoLift $directNoLift;
    private DirectLift $directLift;
    private DirectForklift $directForklift;
    private DistanceShipping $distanceShipping;
    private CheckoutSession $checkoutSession;
    private CustomerSession $customerSession;
    private CustomerRepositoryInterface $customerRepository;
    private AddressRepositoryInterface $addressRepository;
    private ScopeConfigInterface $scopeConfig;
    private RuleCollectionFactory $ruleCollectionFactory;
    private AddressFactory $addressFactory;
    private ItemFactory $itemFactory;
    private QuoteFactory $quoteFactory;
    private StoreManagerInterface $storeManager;
    private Calculation $taxCalculation;

    // Cache for distances within request to avoid duplicate API calls
    private array $distanceCache = [];

    public function __construct(
        Config $config,
        Curl $curl,
        CourierShipping $courierShipping,
        DirectNoLift $directNoLift,
        DirectLift $directLift,
        DirectForklift $directForklift,
        DistanceShipping $distanceShipping,
        CheckoutSession $checkoutSession,
        CustomerSession $customerSession,
        CustomerRepositoryInterface $customerRepository,
        AddressRepositoryInterface $addressRepository,
        ScopeConfigInterface $scopeConfig,
        RuleCollectionFactory $ruleCollectionFactory,
        AddressFactory $addressFactory,
        ItemFactory $itemFactory,
        QuoteFactory $quoteFactory,
        StoreManagerInterface $storeManager,
        Calculation $taxCalculation
    ) {
        $this->config = $config;
        $this->curl = $curl;
        $this->courierShipping = $courierShipping;
        $this->directNoLift = $directNoLift;
        $this->directLift = $directLift;
        $this->directForklift = $directForklift;
        $this->distanceShipping = $distanceShipping;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->customerRepository = $customerRepository;
        $this->addressRepository = $addressRepository;
        $this->scopeConfig = $scopeConfig;
        $this->ruleCollectionFactory = $ruleCollectionFactory;
        $this->addressFactory = $addressFactory;
        $this->itemFactory = $itemFactory;
        $this->quoteFactory = $quoteFactory;
        $this->storeManager = $storeManager;
        $this->taxCalculation = $taxCalculation;
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function getDefaultOrigin(): ?string
    {
        return $this->config->getWarehouseOrigin();
    }

    public function getTargetSku(): string
    {
        return $this->scopeConfig->getValue('carriers/direct_no_lift/target_sku', ScopeInterface::SCOPE_STORE) ?? 'GARDENLAWNS001';
    }

    public function getCustomerPostcode(): ?string
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            $shippingAddress = $quote->getShippingAddress();
            $postcode = $shippingAddress->getPostcode();

            if ($postcode && $postcode !== '*') {
                return $postcode;
            }
        } catch (\Exception $e) {
        }

        if ($this->customerSession->isLoggedIn()) {
            try {
                $customerId = $this->customerSession->getCustomerId();
                $customer = $this->customerRepository->getById($customerId);
                $defaultShippingId = $customer->getDefaultShipping();

                if ($defaultShippingId) {
                    $address = $this->addressRepository->getById($defaultShippingId);
                    return $address->getPostcode();
                }
            } catch (\Exception $e) {
            }
        }

        return null;
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

        // Get Tax Rate
        $taxClassId = $this->scopeConfig->getValue('tax/classes/shipping_tax_class');
        $taxRate = 0;
        if ($taxClassId) {
            $request = $this->taxCalculation->getRateRequest(null, null, null, $this->storeManager->getStore());
            $request->setProductClassId($taxClassId);
            $taxRate = $this->taxCalculation->getRate($request);
        }

        // 1. Courier Shipping
        if ($this->courierShipping->getConfigFlag('active')) {
            $price = $this->courierShipping->calculatePrice($qty);
            if ($price > 0) {
                $costs[] = [
                    'code' => 'couriershipping_couriershipping',
                    'carrier_title' => __($this->courierShipping->getConfigData('title')),
                    'method' => __($this->courierShipping->getConfigData('name')),
                    'description' => __($this->courierShipping->getConfigData('description')),
                    'price' => $price,
                    'source' => 'table'
                ];
            }
        }

        // 2. Distance Shipping (NEW)
        if ($this->distanceShipping->getConfigFlag('active')) {
            // Check max quantity for DistanceShipping
            $maxQty = (float)$this->distanceShipping->getConfigData('max_quantity');
            if ($maxQty <= 0) $maxQty = 950.0;

            if ($qty <= $maxQty) {
                // Calculate distance for DistanceShipping (it might have specific origin)
                $methodOrigin = $this->distanceShipping->getConfigData('specific_origin');
                $distanceKm = $defaultDistanceKm;

                if ($methodOrigin && $methodOrigin !== $defaultOrigin) {
                    $result = $this->getDistance($methodOrigin, $destination);
                    if (isset($result['element']['distance']['value'])) {
                        $distanceKm = $result['element']['distance']['value'] / 1000;
                    } else {
                        $distanceKm = 0; // Failed to calculate specific distance
                    }
                }

                if ($distanceKm > 0) {
                    $price = $this->distanceShipping->calculatePrice($distanceKm, $qty);
                    if ($price > 0) {
                        $costs[] = [
                            'code' => 'distanceshipping_distanceshipping',
                            'carrier_title' => __($this->distanceShipping->getConfigData('title')),
                            'method' => __($this->distanceShipping->getConfigData('name')),
                            'description' => __($this->distanceShipping->getConfigData('description')),
                            'price' => $price,
                            'distance' => $distanceKm,
                            'source' => 'table'
                        ];
                    }
                }
            }
        }

        // 3. Direct Transport Methods
        $processDirectMethod = function ($method, $code) use ($qty, $destination, $defaultOrigin, $defaultDistanceKm) {
            if (!$method->getConfigFlag('active')) {
                return null;
            }

            $methodOrigin = $method->getOrigin();
            $distanceKm = $defaultDistanceKm;

            if ($methodOrigin && $methodOrigin !== $defaultOrigin) {
                $result = $this->getDistance($methodOrigin, $destination);
                if (isset($result['element']['distance']['value'])) {
                    $distanceKm = $result['element']['distance']['value'] / 1000;
                } else {
                    return null;
                }
            }

            // Pass destination to calculatePrice to enable Trans.eu API
            $price = $method->calculatePrice($distanceKm, $qty, $destination, $methodOrigin);

            if ($price > 0) {
                $source = method_exists($method, 'getLastPriceSource') ? $method->getLastPriceSource() : 'unknown';
                $priceDetails = method_exists($method, 'getLastPriceDetails') ? $method->getLastPriceDetails() : [];

                return [
                    'code' => $code . '_' . $code,
                    'carrier_title' => __($method->getConfigData('title')),
                    'method' => __($method->getConfigData('name')),
                    'description' => __($method->getConfigData('description')),
                    'price' => $price,
                    'distance' => $distanceKm,
                    'source' => $source,
                    'price_details' => $priceDetails
                ];
            }
            return null;
        };

        $cost = $processDirectMethod($this->directNoLift, 'direct_no_lift');
        if ($cost) $costs[] = $cost;

        $cost = $processDirectMethod($this->directLift, 'direct_lift');
        if ($cost) $costs[] = $cost;

        $cost = $processDirectMethod($this->directForklift, 'direct_forklift');
        if ($cost) $costs[] = $cost;

        // Add formatted prices (Net & Gross)
        foreach ($costs as &$costItem) {
            $price = $costItem['price'];
            $priceNet = $price;
            $priceGross = $price;

            // Check if shipping prices include tax in configuration
            $shippingIncludesTax = $this->scopeConfig->isSetFlag(
                'tax/calculation/shipping_includes_tax',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );

            if ($shippingIncludesTax) {
                // Price is Gross
                $priceGross = $price;
                $priceNet = $price / (1 + $taxRate / 100);
            } else {
                // Price is Net
                $priceNet = $price;
                $priceGross = $price * (1 + $taxRate / 100);
            }

            // If price_details exists (from Trans.eu), use its gross value for accuracy if available
            if (!empty($costItem['price_details']['gross'])) {
                $priceGross = $costItem['price_details']['gross'];
                // Recalculate net from this specific gross if needed, or use provided net
                if (!empty($costItem['price_details']['net'])) {
                    $priceNet = $costItem['price_details']['net'];
                } else {
                    $priceNet = $priceGross / (1 + $taxRate / 100);
                }
            }

            $costItem['formatted_price_net'] = $this->storeManager->getStore()->getCurrentCurrency()->format($priceNet, [], false);
            $costItem['formatted_price_gross'] = $this->storeManager->getStore()->getCurrentCurrency()->format($priceGross, [], false);
        }

        return $costs;
    }

    public function getPromotionMessage(\Magento\Catalog\Model\Product $product, string $methodCode, float $qty = 1, string $postcode = null): ?string
    {
        try {
            $websiteId = $this->storeManager->getStore()->getWebsiteId();
            $customerGroupId = $this->customerSession->getCustomerGroupId();

            $rules = $this->ruleCollectionFactory->create()
                ->setValidationFilter($websiteId, $customerGroupId)
                ->addFieldToFilter('is_active', 1)
                ->addFieldToFilter('coupon_type', \Magento\SalesRule\Model\Rule::COUPON_TYPE_NO_COUPON);

            if ($rules->getSize() === 0) {
                return null;
            }

            // Use a standard quantity of 1 for validation after removing qty conditions
            $validationQty = 1;
            $price = $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
            $rowTotal = $price * $validationQty;

            /** @var \Magento\Quote\Model\Quote\Address $address */
            $address = $this->addressFactory->create();
            $address->setShippingMethod($methodCode);
            $address->setCountryId('PL');
            if ($postcode) {
                $address->setPostcode($postcode);
            }

            /** @var \Magento\Quote\Model\Quote\Item $item */
            $item = $this->itemFactory->create();
            $item->setProduct($product);
            $item->setQty($validationQty);
            $item->setPrice($price);
            $item->setBasePrice($price);
            $item->setRowTotal($rowTotal);
            $item->setBaseRowTotal($rowTotal);
            $item->setAddress($address);

            // Create mock quote and attach to item and address
            $quote = $this->quoteFactory->create();
            $quote->setStoreId($this->storeManager->getStore()->getId());
            $item->setQuote($quote);

            $address->addItem($item);
            $address->setTotalQty($validationQty);
            $address->setBaseSubtotal($rowTotal);
            $address->setSubtotal($rowTotal);
            $address->setBaseGrandTotal($rowTotal);
            $address->setGrandTotal($rowTotal);
            $address->setCollectShippingRates(true);

            // Attach address to quote
            $quote->setShippingAddress($address);
            $quote->setBillingAddress($address);

            // Force items collection on address to include our item
            $address->setData('all_items', [$item]);
            $address->setData('cached_items_all', [$item]);

            $messages = [];

            foreach ($rules as $rule) {
                $rule->afterLoad();

                // Extract qty requirement BEFORE removing conditions
                $qtyRequirement = $this->extractQtyRequirement($rule->getConditions());

                // Remove quantity conditions to check applicability regardless of qty
                $this->removeQtyConditions($rule->getConditions());

                if (method_exists($address, 'setQuote')) {
                    $address->setQuote($quote);
                }

                if ($rule->validate($address)) {
                    $message = $rule->getDescription() ?: $rule->getName();
                    if ($qtyRequirement) {
                        $message .= ' (' . __('buy: %1 m²', $qtyRequirement) . ')';
                    }
                    $messages[] = $message;
                }
            }

            if (!empty($messages)) {
                return implode('<br>', $messages);
            }

        } catch (\Exception $e) {
            return "Error: " . $e->getMessage();
        }

        return null;
    }

    /**
     * Recursively remove total_qty conditions from the rule
     *
     * @param \Magento\Rule\Model\Condition\Combine $combine
     */
    private function removeQtyConditions($combine)
    {
        $conditions = $combine->getConditions();
        $newConditions = [];
        foreach ($conditions as $condition) {
            if ($condition instanceof \Magento\SalesRule\Model\Rule\Condition\Combine) {
                $this->removeQtyConditions($condition);
                $newConditions[] = $condition;
            } elseif ($condition instanceof \Magento\SalesRule\Model\Rule\Condition\Address) {
                // Remove Total Items Quantity condition
                if ($condition->getAttribute() !== 'total_qty') {
                    $newConditions[] = $condition;
                }
            } else {
                $newConditions[] = $condition;
            }
        }
        $combine->setConditions($newConditions);
    }

    /**
     * Extract required quantity from rule conditions
     *
     * @param \Magento\Rule\Model\Condition\Combine $combine
     * @return float|null
     */
    private function extractQtyRequirement($combine)
    {
        foreach ($combine->getConditions() as $condition) {
            if ($condition instanceof \Magento\SalesRule\Model\Rule\Condition\Combine) {
                $qty = $this->extractQtyRequirement($condition);
                if ($qty) return $qty;
            } elseif ($condition instanceof \Magento\SalesRule\Model\Rule\Condition\Address) {
                if ($condition->getAttribute() === 'total_qty') {
                    return (float)$condition->getValue();
                }
            }
        }
        return null;
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
            $curl = new \Magento\Framework\HTTP\Client\Curl();
            $curl->get($url);
            $responseBody = $curl->getBody();
            $result = json_decode($responseBody, true);

            if (isset($result['rows'][0]['elements'][0]['distance'])) {
                $element = $result['rows'][0]['elements'][0];
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
            $originCoords = $this->getHereCoordinates($origin, $apiKey);
            if (isset($originCoords['error'])) {
                return ['error' => 'Origin Geocoding Error: ' . $originCoords['error']];
            }

            $destCoords = $this->getHereCoordinates($destination, $apiKey);
            if (isset($destCoords['error'])) {
                return ['error' => 'Destination Geocoding Error: ' . $destCoords['error']];
            }

            $params = [
                'transportMode' => 'truck',
                'origin' => $originCoords['lat'] . "," . $originCoords['lng'],
                'destination' => $destCoords['lat'] . "," . $destCoords['lng'],
                'return' => 'summary',
                'apiKey' => $apiKey
            ];

            $truckParams = $this->config->getTruckParameters();

            if (!empty($truckParams['height'])) {
                $params['vehicle[height]'] = (int)($truckParams['height'] * 100);
            }
            if (!empty($truckParams['width'])) {
                $params['vehicle[width]'] = (int)($truckParams['width'] * 100);
            }
            if (!empty($truckParams['length'])) {
                $params['vehicle[length]'] = (int)($truckParams['length'] * 100);
            }
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

            $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $queryString = str_replace(['%2C', '%5B', '%5D'], [',', '[', ']'], $queryString);

            $url = "https://router.hereapi.com/v8/routes?" . $queryString;

            $curl = new \Magento\Framework\HTTP\Client\Curl();
            $curl->get($url);
            $responseBody = $curl->getBody();
            $result = json_decode($responseBody, true);

            if (isset($result['routes'][0]['sections'][0]['summary'])) {
                $summary = $result['routes'][0]['sections'][0]['summary'];
                $distanceText = round($summary['length'] / 1000, 1) . ' km';
                $durationText = round($summary['duration'] / 60) . ' mins';
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
