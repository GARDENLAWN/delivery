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

class CustomShipping extends AbstractCarrier implements
    CarrierInterface
{
    protected ManagerInterface $messageManager;
    protected $_code = 'customshipping';
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
        return false;
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $result = $this->_rateResultFactory->create();

        $method = $this->_rateMethodFactory->create();

        $method->setCarrier($this->_code);
        $method->setCarrierTitle(__($this->getConfigData('title')));

        $method->setMethod($this->_code);
        $method->setMethodTitle(__($this->getConfigData('name')));

        //TODO: pobrane dla zamówienia
        $shippingQuote = [];

        $quote = [];

        $objectManager = ObjectManager::getInstance();
        $cart = $objectManager->get('\Magento\Checkout\Model\Cart');

        $address = $cart->getQuote()->getShippingAddress();

        if ($address) {
            $addressValue =
                '|postcode:"' . $address->getPostcode() .
                '",city:"' . $address->getCity() .
                //  '",street:"' . $address->getStreet() .
                '"|';

            foreach ($shippingQuote['items'] as $item) {
                if ($item['address'] == $addressValue) {
                    $quote[] = $item;
                }
            }

            if ($quote) {
                $price = $quote[0]['price'];
                $method->setPrice($price);
                $method->setCost($price);

                if ($price > 0) {
                    $result->append($method);
                }
            }
        }

        return $result;
    }
}
