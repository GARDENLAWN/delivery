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

class CourierShipping extends AbstractCarrier implements
    CarrierInterface
{
    protected ManagerInterface $messageManager;
    protected $_code = 'couriershipping';
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
            if (strtolower($item->getSku()) == 'trawa-w-rolce') {
                $qnt += $item->getQty();
            }
        }

        $methodCalculatedPrice = false;

        $pricesTable = json_decode($this->getConfigData('prices_table'));
        $delivers = $pricesTable->delivers;

        $deliverAmounts = [];

        $priceFactor = (100 + floatval($this->getConfigData('price_supplement') ?? 0)) / 100;

        foreach ($delivers as $key => $deliver) {
            $deliverAmount = 0.0;

            while ($qnt > 0) {
                $calc = [];
                foreach ($deliver as $i => $item) {
                    $tmpQty = $qnt < $item->m2 ? ceil($qnt / $item->m2) : floor($qnt / $item->m2);
                    $calc [] = ['m2' => $item->m2, 'qnt' => $tmpQty, 'price' => $tmpQty * $item->price];
                }

                $calcMin = array_reduce($calc, function ($a, $b) {
                    return $a['price'] < $b['price'] ? $a : $b;
                }, array_shift($calc));

                $qnt -= floor($calcMin['qnt']) * $calcMin['m2'];
                $deliverAmount += $calcMin['price'];
            }

            $deliverAmounts [] = ceil($deliverAmount * $priceFactor);
        }

        $amount = floatval(min($deliverAmounts));

        if ($amount > 0) {
            $methodCalculatedPrice = true;
        }

        if ($methodCalculatedPrice) {
            $method->setPrice($amount);
            $method->setCost($amount);
            $result->append($method);
        }

        return $result;
    }
}
