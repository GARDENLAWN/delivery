<?php

namespace GardenLawn\Delivery\Model\Carrier;

use Exception;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Message\ManagerInterface;
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
    protected ManagerInterface $messageManager;
    /**
     * @var string
     */
    protected $_code = 'distanceshipping';

    /**
     * @var ResultFactory
     */
    protected ResultFactory $_rateResultFactory;

    /**
     * @var MethodFactory
     */
    protected MethodFactory $_rateMethodFactory;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ErrorFactory $rateErrorFactory
     * @param LoggerInterface $logger
     * @param ResultFactory $rateResultFactory
     * @param MethodFactory $rateMethodFactory
     * @param array $data
     */
    public function __construct(
        ManagerInterface     $messageManager,
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
        $this->messageManager = $messageManager;
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
            $curl = curl_init();

            $apiUrl = $this->getConfigData('api_url')
                . '?key=' . $this->getConfigData('api_key') . '&'
                . sprintf($this->getConfigData('api_params'),
                    str_replace(' ', '%20', $origins ?? ''),
                    str_replace(' ', '%20', $destination ?? '')
                );

            curl_setopt($curl, CURLOPT_URL, $apiUrl);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            $response = curl_exec($curl);

            header('Content-Type: application/json; charset=utf-8');

            $curlError = curl_error($curl);
            if ($curlError) {
                $this->_logger->error($curlError);
            } else {
                $distance = json_decode($response, true);

                if ($distance['rows'] && $distance['rows'][0]['elements'][0]['status'] == 'OK') {
                    $return = floatval($distance['rows'][0]['elements'][0]['distance']['value'] / 1000.00);
                }
            }
        } catch (Exception $e) {
            $this->_logger->error($e);
        } finally {
            curl_close($curl);
            return $return;
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

        $result = $this->_rateResultFactory->create();
        $method = $this->_rateMethodFactory->create();

        $method->setCarrier($this->_code);
        $method->setCarrierTitle(__($this->getConfigData('title')));

        $method->setMethod($this->_code);
        $method->setMethodTitle(__($this->getConfigData('name')));

        $objectManager = ObjectManager::getInstance();
        $cart = $objectManager->get('\Magento\Checkout\Model\Cart');

        $items = $cart->getQuote()->getAllVisibleItems();

        $qnt = 0;
        foreach ($items as $item) {
            if (strtolower($item->getSku()) == 'trawa-w-rolce') {
                $qnt += $item->getQty();
            }
        }

        $methodCalculatedPrice = false;
        $distance = $this->getDistanceForConfigWithPoints(
            $request['dest_street'] . ', ' . $request['dest_postcode'] . ' ' . $request['dest_city']);

        $pricesTable = json_decode($this->getConfigData('prices_table'));
        $delivers = $pricesTable->delivers;

        $deliverAmounts = [];

        foreach ($delivers as $key => $deliver) {
            foreach ($deliver as $i => $item) {
                if ($qnt <= $item->m2) {
                    $priceFactor = (100 + floatval($this->getConfigData('price_supplement') ?? 0)) / 100;
                    if (property_exists($item, 'full_price')) {
                        $deliverAmounts [] = ceil($item->full_price * $item->palette * $priceFactor);
                    } else {
                        $baseKm = $this->getConfigData('base_km');
                        $deliverAmounts [] = ceil($distance * ($item->m2 * $item->price / $item->palette / $baseKm) /
                            $baseKm * $distance * $item->palette * $priceFactor);
                    }
                    break;
                }
            }
        }

        $amount = floatval(min($deliverAmounts));

        if ($amount > 0) {
            $methodCalculatedPrice = true;
        }

        /*{
            "destination_addresses" : ["Namysłowska 21, 46-081 Dobrzeń Wielki, Poland"],
            "origin_addresses" : ["Opole, Poland"],
            "rows" : [{
                    "elements" : [{
                        "distance" : {
                            "text" : "14.1 km",
                            "value" : 14065
                        },
                        "duration" : { "text" : "21 mins", "value" : 1236 },
                        "status" : "OK"
                        }]
                    }],
            "status" : "OK"
        }*/

        if ($methodCalculatedPrice) {
            $method->setPrice($amount);
            $method->setCost($amount);
            $method->setDesciption('<div>test</div>');
            $result->append($method);
        }

        return $result;
    }
}
