<?php

namespace GardenLawn\Delivery\Model\Carrier;

use GardenLawn\Delivery\Service\DistanceService;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;
use Magento\Framework\Serialize\Serializer\Json;

class DistanceShipping extends AbstractCarrier implements CarrierInterface
{
    protected $_code = 'distanceshipping';
    protected ResultFactory $_rateResultFactory;
    protected MethodFactory $_rateMethodFactory;
    protected DistanceService $distanceService;
    protected Json $json;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory         $rateErrorFactory,
        LoggerInterface      $logger,
        ResultFactory        $rateResultFactory,
        MethodFactory        $rateMethodFactory,
        DistanceService      $distanceService,
        Json                 $json,
        array                $data = []
    )
    {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->distanceService = $distanceService;
        $this->json = $json;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * get allowed methods
     * @return array
     */
    public function getAllowedMethods(): array
    {
        return [$this->_code => $this->getConfigData('name')];
    }

    public function getDistanceForConfig($address): float
    {
        $origin = $this->_scopeConfig->getValue('delivery/general/warehouse_origin');
        if (!$origin) {
            return 0.0;
        }
        return $this->distanceService->getDistance($origin, $address);
    }

    public function getDistanceForConfigWithPoints($address): float
    {
        $origin = $this->_scopeConfig->getValue('delivery/general/warehouse_origin');
        if (!$origin) {
            return 0.0;
        }

        $pointsJson = $this->getConfigData('points');
        if (!$pointsJson) {
            return $this->distanceService->getDistance($origin, $address);
        }

        $pointsData = json_decode($pointsJson);
        if (!$pointsData || !isset($pointsData->points)) {
            return $this->distanceService->getDistance($origin, $address);
        }

        $points = [$origin];
        $points = array_merge($points, $pointsData->points);
        $points[] = $address;

        return $this->distanceService->getDistanceForPoints($points);
    }

    public function calculatePrice(float $distance, float $qnt): float
    {
        // 1. Get Pricing Table from Config
        $pricingTableJson = $this->getConfigData('pricing_table');
        $pricingTable = [];

        if ($pricingTableJson) {
            try {
                $pricingTable = $this->json->unserialize($pricingTableJson);
            } catch (\Exception $e) {
                $this->_logger->error('DistanceShipping: Error unserializing pricing table: ' . $e->getMessage());
            }
        }

        // Fallback to default table if config is empty or invalid
        if (empty($pricingTable)) {
            // Default table structure compatible with logic below
            $defaultTable = [
                ['m2' => 50, 'price' => 16.73], ['m2' => 100, 'price' => 9.73],
                ['m2' => 150, 'price' => 7.4],  ['m2' => 200, 'price' => 6.23],
                ['m2' => 250, 'price' => 5.53], ['m2' => 300, 'price' => 5.06],
                ['m2' => 350, 'price' => 4.73], ['m2' => 400, 'price' => 4.48],
                ['m2' => 450, 'price' => 4.28], ['m2' => 500, 'price' => 4.13],
                ['m2' => 550, 'price' => 4.0],  ['m2' => 600, 'price' => 3.89],
                ['m2' => 650, 'price' => 3.8],  ['m2' => 700, 'price' => 3.73],
                ['m2' => 750, 'price' => 3.66], ['m2' => 800, 'price' => 3.6],
                ['m2' => 850, 'price' => 3.55], ['m2' => 900, 'price' => 3.51],
                ['m2' => 950, 'price' => 3.46]
            ];
            // Convert to array format used by ArraySerialized (usually has unique IDs as keys)
            // But here we just need a list of items with m2 and price
            $pricingTable = $defaultTable;
        } else {
            // Ensure array is just values if it comes from ArraySerialized with IDs
            $pricingTable = array_values($pricingTable);
        }

        // 2. Sort by m2 ascending
        usort($pricingTable, function ($a, $b) {
            return $a['m2'] <=> $b['m2'];
        });

        // 3. Find matching tier
        $pricePerM2 = 0.0;
        foreach ($pricingTable as $tier) {
            if ($qnt <= $tier['m2']) {
                $pricePerM2 = (float)$tier['price'];
                break;
            }
        }

        // If quantity is larger than the largest tier, use the price of the largest tier
        if ($pricePerM2 == 0.0 && !empty($pricingTable)) {
            $lastTier = end($pricingTable);
            $pricePerM2 = (float)$lastTier['price'];
        }

        if ($pricePerM2 <= 0) {
            return 0.0;
        }

        // 4. Calculate Base Cost
        $baseCost = $qnt * $pricePerM2;

        // 5. Calculate Distance Supplement
        $distanceSupplement = 0.0;
        if ($distance > 80) {
            $distanceSupplement = ($distance - 80) * 5.0;
        }

        // 6. Apply Price Supplement %
        $priceFactor = (100 + floatval($this->getConfigData('price_supplement') ?? 0)) / 100;
        
        $totalPrice = ($baseCost + $distanceSupplement) * $priceFactor;

        // Check if shipping prices include tax in configuration
        $shippingIncludesTax = $this->_scopeConfig->isSetFlag(
            'tax/calculation/shipping_includes_tax',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        // Assuming the calculated price is GROSS based on typical configuration
        if (!$shippingIncludesTax) {
             // If config says prices exclude tax, but we calculated gross, we might need to strip tax.
             // However, without tax rate info here, we return as is, assuming the base parameters are set according to the tax config.
        }

        return $totalPrice;
    }

    public function collectRates(RateRequest $request): Result|bool
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $items = $request->getAllItems();
        if (empty($items)) {
            return false;
        }

        $targetSku = strtolower($this->getConfigData('target_sku') ?? 'GARDENLAWNS001');

        $qnt = 0;
        foreach ($items as $item) {
            // Skip parent items for configurable products to avoid double counting
            if ($item->getProduct()->isVirtual() || $item->getParentItem()) {
                continue;
            }

            if (strtolower($item->getSku()) === $targetSku) {
                $qnt += $item->getQty();
            }
        }

        if ($qnt <= 0) {
            return false;
        }

        // 1. Validate Max Quantity
        $maxQty = (float)$this->getConfigData('max_quantity');
        // Default to 950 if not set
        if ($maxQty <= 0) {
            $maxQty = 950.0;
        }

        if ($qnt > $maxQty) {
            return false;
        }

        // 2. Calculate Distance
        $destination = $request->getDestStreet() . ', ' . $request->getDestPostcode() . ' ' . $request->getDestCity();
        
        try {
            $distance = $this->getDistanceForConfigWithPoints($destination);
        } catch (\Exception $e) {
            $this->_logger->error('DistanceShipping: Could not calculate distance: ' . $e->getMessage());
            return false;
        }

        if ($distance <= 0) {
            $this->_logger->warning('DistanceShipping: Distance is 0 or could not be calculated');
            return false;
        }

        // 3. Calculate Price
        $amount = $this->calculatePrice($distance, $qnt);

        if ($amount <= 0) {
            return false;
        }

        $result = $this->_rateResultFactory->create();
        $method = $this->_rateMethodFactory->create();

        $method->setCarrier($this->_code);
        
        // 4. Set Method Title with Distance
        $methodTitle = (string)__($this->getConfigData('name'));
        $methodTitle .= ' (' . __('Dystans: %1 km', round($distance, 1)) . ')';
        
        $method->setCarrierTitle((string)__($this->getConfigData('title')));
        $method->setMethod($this->_code);
        $method->setMethodTitle($methodTitle);
        $method->setPrice($amount);
        $method->setCost($amount);

        $result->append($method);

        return $result;
    }
}
