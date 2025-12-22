<?php

namespace GardenLawn\Delivery\Model\Carrier;

use Exception;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
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
    protected Curl $curl;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory         $rateErrorFactory,
        LoggerInterface      $logger,
        ResultFactory        $rateResultFactory,
        MethodFactory        $rateMethodFactory,
        CheckoutSession      $checkoutSession,
        Curl                 $curl,
        array                $data = []
    )
    {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->checkoutSession = $checkoutSession;
        $this->curl = $curl;
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
        $points = [$this->getConfigData('origins'), $address];
        return $this->getDistanceForPoints($points);
    }

    public function getDistanceForConfigWithPoints($address): float
    {
        $points = json_decode($this->getConfigData('points'));
        array_unshift($points->points, $this->getConfigData('origins'));
        $points->points [] = $address;
        return $this->getDistanceForPoints($points->points);
    }

    public function getDistanceForPoints($points): float
    {
        $distance = 0;

        for ($i = 0; $i < count($points) - 1; $i++) {
            $distance += $this->getDistance($points[$i], $points[$i + 1]);
        }

        return $distance;
    }

    public function getDistance($origins, $destination): float
    {
        $return = 0.00;

        try {
            $apiUrl = $this->getConfigData('api_url');
            $apiKey = $this->getConfigData('api_key');
            $apiParams = $this->getConfigData('api_params');

            if (!$apiUrl || !$apiKey) {
                $this->_logger->warning('DistanceShipping: Missing API configuration');
                return $return;
            }

            $fullApiUrl = $apiUrl . '?key=' . $apiKey . '&' . sprintf(
                $apiParams ?? 'origins=%s&destinations=%s',
                urlencode($origins ?? ''),
                urlencode($destination ?? '')
            );

            $this->curl->get($fullApiUrl);
            $response = $this->curl->getBody();
            $distance = json_decode($response, true);

            if ($distance && isset($distance['rows'][0]['elements'][0]['status'])
                && $distance['rows'][0]['elements'][0]['status'] === 'OK') {
                $return = floatval($distance['rows'][0]['elements'][0]['distance']['value'] / 1000.00);
            }
        } catch (Exception $e) {
            $this->_logger->error('DistanceShipping getDistance: ' . $e->getMessage());
        }

        return $return;
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

        $destination = $request->getDestStreet() . ', ' . $request->getDestPostcode() . ' ' . $request->getDestCity();
        $distance = $this->getDistanceForConfigWithPoints($destination);

        if ($distance <= 0) {
            $this->_logger->warning('DistanceShipping: Could not calculate distance');
            return false;
        }

        $pricesTableJson = $this->getConfigData('prices_table');
        if (!$pricesTableJson) {
            $this->_logger->warning('DistanceShipping: prices_table configuration is missing');
            return false;
        }

        $pricesTable = json_decode($pricesTableJson);
        if (!$pricesTable || !isset($pricesTable->delivers)) {
            $this->_logger->error('DistanceShipping: Invalid prices_table JSON format');
            return false;
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
