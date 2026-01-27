<?php

namespace GardenLawn\Delivery\Model\Carrier;

use GardenLawn\Delivery\Service\DistanceService;
use GardenLawn\Delivery\Service\TransEuQuoteService;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;

abstract class AbstractDirectTransport extends AbstractCarrier implements CarrierInterface
{
    protected ResultFactory $_rateResultFactory;
    protected MethodFactory $_rateMethodFactory;
    protected DistanceService $distanceService;
    protected TransEuQuoteService $transEuQuoteService;
    protected string $lastPriceSource = 'table';
    protected array $lastPriceDetails = [];

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory         $rateErrorFactory,
        LoggerInterface      $logger,
        ResultFactory        $rateResultFactory,
        MethodFactory        $rateMethodFactory,
        DistanceService      $distanceService,
        TransEuQuoteService  $transEuQuoteService,
        array                $data = []
    ) {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->distanceService = $distanceService;
        $this->transEuQuoteService = $transEuQuoteService;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function getAllowedMethods(): array
    {
        return [$this->_code => $this->getConfigData('name')];
    }

    /**
     * Get origin address for this specific method.
     * Falls back to general warehouse origin if specific origin is not set.
     */
    public function getOrigin(): string
    {
        $specificOrigin = $this->getConfigData('specific_origin');
        if ($specificOrigin) {
            return $specificOrigin;
        }
        return (string)$this->_scopeConfig->getValue('delivery/general/warehouse_origin');
    }

    public function getLastPriceSource(): string
    {
        return $this->lastPriceSource;
    }

    public function getLastPriceDetails(): array
    {
        return $this->lastPriceDetails;
    }

    /**
     * Calculate price for given distance and quantity
     * Returns 0.0 if method is not applicable (e.g. max qty exceeded)
     */
    public function calculatePrice(float $distance, float $qty, string $destination = '', string $origin = ''): float
    {
        $this->lastPriceSource = 'table';
        $this->lastPriceDetails = [];

        if (!$this->getConfigFlag('active')) {
            return 0.0;
        }

        $maxQty = (float)$this->getConfigData('max_qty');
        if ($maxQty > 0 && $qty > $maxQty) {
            return 0.0;
        }

        // Try Trans.eu first if enabled
        if ($this->getConfigFlag('use_transeu_api') && $destination) {
            if (!$origin) {
                $origin = $this->getOrigin();
            }

            $transEuPrice = $this->transEuQuoteService->getPrice(
                $this->_code,
                $origin,
                $destination,
                $distance,
                $qty
            );

            if ($transEuPrice !== null) {
                $this->lastPriceSource = 'transeu';
                $this->lastPriceDetails = $this->transEuQuoteService->getLastPriceDetails();
                return $transEuPrice;
            }
        }

        return $this->calculateTierPrice($distance);
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

        // 2. Check Max Quantity Limit
        $maxQty = (float)$this->getConfigData('max_qty');
        if ($maxQty > 0 && $qnt > $maxQty) {
            return false;
        }

        // 3. Calculate Distance
        $origin = $this->getOrigin();
        if (!$origin) {
            return false;
        }

        // Build destination address carefully
        $destParts = [];
        if ($request->getDestStreet()) {
            $destParts[] = $request->getDestStreet();
        }
        if ($request->getDestPostcode()) {
            $destParts[] = $request->getDestPostcode();
        }
        if ($request->getDestCity()) {
            $destParts[] = $request->getDestCity();
        }
        if ($request->getDestCountryId()) {
            $destParts[] = $request->getDestCountryId();
        }

        $destination = implode(', ', $destParts);

        // If address is too short (e.g. just country), skip calculation to avoid API errors/costs
        if (strlen($destination) < 5) {
            return false;
        }

        try {
            $distance = $this->distanceService->getDistance($origin, $destination);
        } catch (\Exception $e) {
            $this->_logger->error($this->_code . ': ' . $e->getMessage());
            return false;
        }

        if ($distance <= 0) {
            return false;
        }

        // 4. Calculate Price
        // Use the updated calculatePrice method which handles Trans.eu logic internally
        $price = $this->calculatePrice($distance, $qnt, $destination, $origin);

        if ($price <= 0) {
            return false;
        }

        // 5. Create Result
        $result = $this->_rateResultFactory->create();
        $method = $this->_rateMethodFactory->create();

        $method->setCarrier($this->_code);
        $method->setCarrierTitle((string)__($this->getConfigData('title')));
        $method->setMethod($this->_code);
        $method->setMethodTitle((string)__($this->getConfigData('name')));
        $method->setPrice($price);
        $method->setCost($price);

        $result->append($method);

        return $result;
    }

    protected function calculateTierPrice(float $distance): float
    {
        $configJson = $this->getConfigData('pricing_config');
        if (!$configJson) {
            return 0.0;
        }

        $config = json_decode($configJson, true);
        if (!$config || !is_array($config)) {
            return 0.0;
        }

        $tiers = [];

        // Handle old format {"tiers": [...]}
        if (isset($config['tiers']) && is_array($config['tiers'])) {
            $tiers = $config['tiers'];
        } else {
            // Handle new format (ArraySerialized) - array of rows
            // Filter out empty rows if any
            foreach ($config as $row) {
                if (isset($row['min_distance']) && isset($row['price']) && isset($row['type'])) {
                    $tiers[] = $row;
                }
            }
        }

        if (empty($tiers)) {
            return 0.0;
        }

        // Sort tiers by distance descending to find the matching range easily
        usort($tiers, function ($a, $b) {
            return $b['min_distance'] <=> $a['min_distance'];
        });

        $price = 0.0;
        foreach ($tiers as $tier) {
            if (!isset($tier['min_distance'], $tier['price'], $tier['type'])) {
                continue;
            }

            if ($distance >= $tier['min_distance']) {
                if ($tier['type'] === 'fixed') {
                    $price = (float)$tier['price'];
                } elseif ($tier['type'] === 'per_km') {
                    $price = $distance * (float)$tier['price'];
                }
                break; // Found the matching tier
            }
        }

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

        return $price;
    }
}
