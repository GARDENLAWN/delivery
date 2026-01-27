<?php

namespace GardenLawn\Delivery\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;

class CourierShipping extends AbstractCarrier implements CarrierInterface
{
    protected $_code = 'couriershipping';
    protected ResultFactory $_rateResultFactory;
    protected MethodFactory $_rateMethodFactory;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory         $rateErrorFactory,
        LoggerInterface      $logger,
        ResultFactory        $rateResultFactory,
        MethodFactory        $rateMethodFactory,
        array                $data = []
    )
    {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
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

    public function calculatePrice(float $qnt): float
    {
        // Default values if not configured
        $m2PerPalette = 35.0;
        $pricePerPalette = 327.0;

        // Try to get from config
        $pricesTableJson = $this->getConfigData('prices_table');
        if ($pricesTableJson) {
            $pricesTable = json_decode($pricesTableJson);
            if ($pricesTable && isset($pricesTable->delivers)) {
                // Assuming the first entry in the first deliver array defines the base unit
                // Example JSON: {"delivers": {"courier": [{"m2": 35, "price": 327}]}}
                foreach ($pricesTable->delivers as $deliver) {
                    if (is_array($deliver) && !empty($deliver)) {
                        $firstItem = $deliver[0];
                        if (isset($firstItem->m2, $firstItem->price)) {
                            $m2PerPalette = floatval($firstItem->m2);
                            $pricePerPalette = floatval($firstItem->price);
                            break;
                        }
                    }
                }
            }
        }

        if ($m2PerPalette <= 0) {
            return 0.0;
        }

        $palettes = ceil($qnt / $m2PerPalette);
        $basePrice = $palettes * $pricePerPalette;

        $priceFactor = (100 + floatval($this->getConfigData('price_supplement') ?? 0)) / 100;

        $price = ceil($basePrice * $priceFactor);

        // Check if shipping prices include tax in configuration
        $shippingIncludesTax = $this->_scopeConfig->isSetFlag(
            'tax/calculation/shipping_includes_tax',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        if ($shippingIncludesTax) {
            // If prices include tax, we need to return the gross price
            // The calculation above seems to produce a gross price (based on typical courier pricing)
            // If the base price from config is NET, we might need to add tax here.
            // Assuming the configured price is GROSS for now as per common practice with simple tables.
            return $price;
        } else {
            // If prices exclude tax, we need to return the net price
            // We need to know the tax rate to calculate net from gross
            // For now, returning the calculated price as is, assuming it matches the config expectation
            return $price;
        }
    }

    /**
     * @param RateRequest $request
     * @return bool|Result
     */
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

        $amount = $this->calculatePrice($qnt);

        if ($amount <= 0) {
            return false;
        }

        $result = $this->_rateResultFactory->create();
        $method = $this->_rateMethodFactory->create();

        $method->setCarrier($this->_code);
        $method->setCarrierTitle((string)__($this->getConfigData('title')));
        $method->setMethod($this->_code);
        $method->setMethodTitle((string)__($this->getConfigData('name')));
        $method->setPrice($amount);
        $method->setCost($amount);

        $result->append($method);

        return $result;
    }
}
