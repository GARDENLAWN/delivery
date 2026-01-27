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
            $pricingTable = $defaultTable;
        } else {
            $pricingTable = array_values($pricingTable);
        }

        // 2. Sort by m2 ascending
        usort($pricingTable, function ($a, $b) {
            return $a['m2'] <=> $b['m2'];
        });

        // 3. Find matching tier (first tier where m2 >= qnt)
        $selectedTier = null;
        foreach ($pricingTable as $tier) {
            if ($tier['m2'] >= $qnt) {
                $selectedTier = $tier;
                break;
            }
        }

        // If quantity is larger than the largest tier, use the largest tier
        if (!$selectedTier && !empty($pricingTable)) {
            $selectedTier = end($pricingTable);
        }

        if (!$selectedTier) {
            return 0.0;
        }

        // 4. Calculate Base Cost: (m2 from tier) * (price from tier)
        $baseCost = (float)$selectedTier['m2'] * (float)$selectedTier['price'];

        // 5. Calculate Distance Supplement
        $freeDistanceLimit = $this->getConfigData('free_distance_limit');
        if ($freeDistanceLimit === null || $freeDistanceLimit === '') {
            $freeDistanceLimit = 80.0; // Default fallback
        } else {
            $freeDistanceLimit = (float)$freeDistanceLimit;
        }

        $distanceSurcharge = $this->getConfigData('distance_surcharge');
        if ($distanceSurcharge === null || $distanceSurcharge === '') {
            $distanceSurcharge = 5.0; // Default fallback
        } else {
            $distanceSurcharge = (float)$distanceSurcharge;
        }

        $distanceSupplement = 0.0;
        if ($distance > $freeDistanceLimit) {
            $distanceSupplement = ($distance - $freeDistanceLimit) * $distanceSurcharge;
        }

        // 6. Apply Price Supplement %
        $priceFactor = (100 + floatval($this->getConfigData('price_supplement') ?? 0)) / 100;

        $totalPrice = ($baseCost + $distanceSupplement) * $priceFactor;

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
        if ($maxQty <= 0) {
            $maxQty = 950.0; // Default fallback
        }

        if ($qnt > $maxQty) {
            return false;
        }

        // 2. Calculate Distance (from specific_origin or warehouse_origin to destination)

        // Try to get specific origin for this method first
        $origin = $this->getConfigData('specific_origin');

        // Fallback to global warehouse origin if specific is not set
        if (!$origin) {
            $origin = $this->_scopeConfig->getValue('delivery/general/warehouse_origin');
        }

        if (!$origin) {
            $this->_logger->warning('DistanceShipping: Origin address not configured');
            return false;
        }

        $destination = $request->getDestStreet() . ', ' . $request->getDestPostcode() . ' ' . $request->getDestCity();

        try {
            // Using DistanceService (which handles Here/Google logic)
            $distance = $this->distanceService->getDistance($origin, $destination);
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
