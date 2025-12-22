<?php

namespace GardenLawn\Delivery\Model\Carrier;

use Magento\Checkout\Model\Session as CheckoutSession;
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
    protected CheckoutSession $checkoutSession;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory         $rateErrorFactory,
        LoggerInterface      $logger,
        ResultFactory        $rateResultFactory,
        MethodFactory        $rateMethodFactory,
        CheckoutSession      $checkoutSession,
        array                $data = []
    )
    {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->checkoutSession = $checkoutSession;
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

    /**
     * @param RateRequest $request
     * @return bool|Result
     */
    public function collectRates(RateRequest $request): Result|bool
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $quote = $this->checkoutSession->getQuote();
        if (!$quote) {
            return false;
        }

        $items = $quote->getAllVisibleItems();
        $targetSku = strtolower($this->getConfigData('target_sku') ?? 'trawa-w-rolce');

        $qnt = 0;
        foreach ($items as $item) {
            if (strtolower($item->getSku()) === $targetSku) {
                $qnt += $item->getQty();
            }
        }

        if ($qnt <= 0) {
            return false;
        }

        $pricesTableJson = $this->getConfigData('prices_table');
        if (!$pricesTableJson) {
            $this->_logger->warning('CourierShipping: prices_table configuration is missing');
            return false;
        }

        $pricesTable = json_decode($pricesTableJson);
        if (!$pricesTable || !isset($pricesTable->delivers)) {
            $this->_logger->error('CourierShipping: Invalid prices_table JSON format');
            return false;
        }

        $delivers = $pricesTable->delivers;
        $deliverAmounts = [];
        $priceFactor = (100 + floatval($this->getConfigData('price_supplement') ?? 0)) / 100;

        foreach ($delivers as $deliver) {
            $deliverAmount = 0.0;
            $remainingQnt = $qnt;

            while ($remainingQnt > 0) {
                $calc = [];
                foreach ($deliver as $item) {
                    if (!isset($item->m2, $item->price)) {
                        continue;
                    }
                    $tmpQty = $remainingQnt < $item->m2 ? ceil($remainingQnt / $item->m2) : floor($remainingQnt / $item->m2);
                    $calc[] = ['m2' => $item->m2, 'qnt' => $tmpQty, 'price' => $tmpQty * $item->price];
                }

                if (empty($calc)) {
                    break;
                }

                $calcMin = array_reduce($calc, function ($a, $b) {
                    return $a['price'] < $b['price'] ? $a : $b;
                }, array_shift($calc));

                $remainingQnt -= floor($calcMin['qnt']) * $calcMin['m2'];
                $deliverAmount += $calcMin['price'];
            }

            $deliverAmounts[] = ceil($deliverAmount * $priceFactor);
        }

        if (empty($deliverAmounts)) {
            return false;
        }

        $amount = floatval(min($deliverAmounts));

        if ($amount <= 0) {
            return false;
        }

        $result = $this->_rateResultFactory->create();
        $method = $this->_rateMethodFactory->create();

        $method->setCarrier($this->_code);
        $method->setCarrierTitle(__($this->getConfigData('title')));
        $method->setMethod($this->_code);
        $method->setMethodTitle(__($this->getConfigData('name')));
        $method->setPrice($amount);
        $method->setCost($amount);

        $result->append($method);

        return $result;
    }
}
