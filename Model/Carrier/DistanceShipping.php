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

class DistanceShipping extends AbstractCarrier implements CarrierInterface
{
    protected $_code = 'distanceshipping';
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
        $pricesTableJson = $this->getConfigData('prices_table');
        if (!$pricesTableJson) {
            return 0.0;
        }

        $pricesTable = json_decode($pricesTableJson);
        if (!$pricesTable || !isset($pricesTable->delivers)) {
            return 0.0;
        }

        $delivers = $pricesTable->delivers;
        $deliverAmounts = [];
        $priceFactor = (100 + floatval($this->getConfigData('price_supplement') ?? 0)) / 100;
        $baseKm = floatval($this->getConfigData('base_km') ?? 1);

        foreach ($delivers as $deliver) {
            foreach ($deliver as $item) {
                if (!isset($item->m2, $item->price)) {
                    continue;
                }

                if ($qnt <= $item->m2) {
                    if (property_exists($item, 'full_price') && isset($item->palette)) {
                        $deliverAmounts[] = ceil($item->full_price * $item->palette * $priceFactor);
                    } else {
                        if ($baseKm > 0 && isset($item->palette)) {
                            $deliverAmounts[] = ceil($distance * ($item->m2 * $item->price / $item->palette / $baseKm) /
                                $baseKm * $distance * $item->palette * $priceFactor);
                        }
                    }
                    break;
                }
            }
        }

        if (empty($deliverAmounts)) {
            return 0.0;
        }

        return floatval(min($deliverAmounts)) / 1000.0;
    }

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

        $destination = $request->getDestStreet() . ', ' . $request->getDestPostcode() . ' ' . $request->getDestCity();
        $distance = $this->getDistanceForConfigWithPoints($destination);

        if ($distance <= 0) {
            $this->_logger->warning('DistanceShipping: Could not calculate distance');
            return false;
        }

        $amount = $this->calculatePrice($distance, $qnt);

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
