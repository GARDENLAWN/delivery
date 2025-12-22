<?php

namespace GardenLawn\Delivery\Model\Carrier;

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

class CourierWithElevatorShipping extends AbstractCarrier implements CarrierInterface
{
    protected $_code = 'courierwithelevatorshipping';
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

        $apiUrl = $this->getConfigData('api_url');
        $apiKey = $this->getConfigData('api_key');
        $apiParams = $this->getConfigData('api_params');
        $origins = $this->getConfigData('origins');

        if (!$apiUrl || !$apiKey || !$origins) {
            $this->_logger->warning('CourierWithElevatorShipping: Missing API configuration');
            return false;
        }

        $destination = $request->getDestStreet() . ', ' . $request->getDestPostcode() . ' ' . $request->getDestCity();
        $fullApiUrl = $apiUrl . '?key=' . $apiKey . '&' . sprintf(
            $apiParams ?? 'origins=%s&destinations=%s',
            urlencode($origins),
            urlencode($destination)
        );

        try {
            $this->curl->get($fullApiUrl);
            $response = $this->curl->getBody();
            $distance = json_decode($response, true);

            if (!$distance || !isset($distance['rows'][0]['elements'][0]['status'])) {
                $this->_logger->error('CourierWithElevatorShipping: Invalid API response');
                return false;
            }

            if ($distance['rows'][0]['elements'][0]['status'] !== 'OK') {
                $this->_logger->warning('CourierWithElevatorShipping: Distance calculation failed - ' . ($distance['rows'][0]['elements'][0]['status'] ?? 'unknown'));
                return false;
            }

            $customerKm = $distance['rows'][0]['elements'][0]['distance']['value'] / 1000;

            if ($customerKm <= 0) {
                return false;
            }

            $pricePerKm = floatval($this->getConfigData('price') ?? 0);
            $factorMin = floatval($this->getConfigData('factor_min') ?? 1);
            $factorMax = floatval($this->getConfigData('factor_max') ?? 1.5);
            $maxLoad = floatval($this->getConfigData('max_load') ?? 100);

            if ($pricePerKm <= 0 || $maxLoad <= 0) {
                $this->_logger->warning('CourierWithElevatorShipping: Invalid pricing configuration');
                return false;
            }

            $diffFactor = $factorMax - $factorMin;
            $maxLoadFactor = $maxLoad / $qnt;
            $factor = $factorMax - $diffFactor / $maxLoadFactor;
            $priceKm = $pricePerKm * $factor;
            $priceCustomerKm = $priceKm * $customerKm;
            $price = ceil($priceCustomerKm * $maxLoadFactor);

            if ($price <= 0) {
                return false;
            }

            $result = $this->_rateResultFactory->create();
            $method = $this->_rateMethodFactory->create();

            $method->setCarrier($this->_code);
            $method->setCarrierTitle(__($this->getConfigData('title')));
            $method->setMethod($this->_code);
            $method->setMethodTitle(__($this->getConfigData('name')));
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
