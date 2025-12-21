<?php

namespace GardenLawn\Delivery\Model\Carrier;

use Magento\Customer\Model\Session;
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

class CourierWithElevatorShipping extends AbstractCarrier implements
    CarrierInterface
{
    protected ManagerInterface $messageManager;
    protected $_code = 'courierwithelevatorshipping';
    protected ResultFactory $_rateResultFactory;
    protected MethodFactory $_rateMethodFactory;
    protected Session $customerSession;

    public function __construct(
        ManagerInterface     $messageManager,
        ScopeConfigInterface $scopeConfig,
        ErrorFactory         $rateErrorFactory,
        LoggerInterface      $logger,
        ResultFactory        $rateResultFactory,
        MethodFactory        $rateMethodFactory,
        Session              $customerSession,
        array                $data = []
    )
    {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->messageManager = $messageManager;
        $this->customerSession = $customerSession;
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
            if ($item->getSku() == 'trawa-w-rolce') {
                $qnt += $item->getQty();
            }
        }

        $curl = curl_init();

        $apiUrl = $this->getConfigData('api_url')
            . '?key=' . $this->getConfigData('api_key') . '&'
            . sprintf($this->getConfigData('api_params') ?? '',
                str_replace(' ', '%20', $this->getConfigData('origins') ?? ''),
                str_replace(' ', '%20', $request['dest_street'] . ', ' . $request['dest_postcode'] . ' ' . $request['dest_city'])
            );

        curl_setopt($curl, CURLOPT_URL, $apiUrl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($curl);

        header('Content-Type: application/json; charset=utf-8');

        $customerKm = 0;
        $methodCalculatedKm = false;

        if (curl_error($curl)) {

        } else {
            $distance = json_decode($response, true);

            if ($distance['rows'][0]['elements'][0]['status'] == 'OK') {
                $customerKm = $distance['rows'][0]['elements'][0]['distance']['value'] / 1000;
                if ($customerKm > 0) {
                    $methodCalculatedKm = true;
                }
            }
        }

        if ($methodCalculatedKm) {
            $pricePerKm = $this->getConfigData('price');
            $factorMin = $this->getConfigData('factorMin');
            $factorMax = $this->getConfigData('factorMax');
            $maxLoad = $this->getConfigData('qnty');
            $diffFactor = $factorMax - $factorMin;
            $maxLoadFactor = $maxLoad / $qnt;
            $factor = $factorMax - $diffFactor / $maxLoadFactor;
            $priceKm = $pricePerKm * $factor;
            $priceCustomerKm = $priceKm * $customerKm;
            $price = ceil($priceCustomerKm * $maxLoadFactor);

            $method->setPrice($price);
            $method->setCost($price);

            if ($price > 0) {
                $result->append($method);
            }
        }
        return $result;
    }
}
