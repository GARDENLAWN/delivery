<?php

namespace GardenLawn\Delivery\Model\Carrier;

use GardenLawn\Delivery\Service\DistanceService;
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

class CourierWithElevatorShipping extends AbstractCarrier implements CarrierInterface
{
    protected $_code = 'courierwithelevatorshipping';
    protected ResultFactory $_rateResultFactory;
    protected MethodFactory $_rateMethodFactory;
    protected CheckoutSession $checkoutSession;
    protected DistanceService $distanceService;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory         $rateErrorFactory,
        LoggerInterface      $logger,
        ResultFactory        $rateResultFactory,
        MethodFactory        $rateMethodFactory,
        CheckoutSession      $checkoutSession,
        DistanceService      $distanceService,
        array                $data = []
    )
    {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->checkoutSession = $checkoutSession;
        $this->distanceService = $distanceService;
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
        $pricePerKm = floatval($this->getConfigData('price') ?? 0);
        $factorMin = floatval($this->getConfigData('factor_min') ?? 1);
        $factorMax = floatval($this->getConfigData('factor_max') ?? 1.5);
        $maxLoad = floatval($this->getConfigData('max_load') ?? 100);

        if ($pricePerKm <= 0 || $maxLoad <= 0) {
            return 0.0;
        }

        $diffFactor = $factorMax - $factorMin;
        $maxLoadFactor = $maxLoad / $qnt;
        $factor = $factorMax - $diffFactor / $maxLoadFactor;
        $priceKm = $pricePerKm * $factor;
        $priceCustomerKm = $priceKm * $distance;
        $price = ceil($priceCustomerKm * $maxLoadFactor);

        return $price > 0 ? $price : 0.0;
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

        $origins = $this->_scopeConfig->getValue('delivery/general/warehouse_origin');
        if (!$origins) {
            $this->_logger->warning('CourierWithElevatorShipping: Warehouse origin is not configured');
            return false;
        }

        $destination = $request->getDestStreet() . ', ' . $request->getDestPostcode() . ' ' . $request->getDestCity();

        try {
            $customerKm = $this->distanceService->getDistance($origins, $destination);

            if ($customerKm <= 0) {
                return false;
            }

            $price = $this->calculatePrice($customerKm, $qnt);

            if ($price <= 0) {
                return false;
            }

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
        } catch (\Exception $e) {
            $this->_logger->error('CourierWithElevatorShipping: ' . $e->getMessage());
            return false;
        }
    }
}
