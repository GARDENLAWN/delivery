<?php

namespace GardenLawn\Delivery\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;

class CustomFreeShipping extends AbstractCarrier implements CarrierInterface
{
    protected ManagerInterface $messageManager;
    /**
     * @var string
     */
    protected $_code = 'customfreeshipping';

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

        $skus = [];
        foreach ($request['all_items'] as $key => $value) {
            if (!in_array($value['sku'], $skus)) {
                $skus[] = $value['sku'];
            }
        }

        $freeSkus = explode(";", $this->getConfigData('products_sku') ?? '');
        array_pop($freeSkus);
        $isFree = false;

        $diffSkus = array_diff($skus, $freeSkus);

        if (count($diffSkus) == 0 && count(array_intersect($freeSkus, $skus)) > 0) {
            $isFree = true;
        }

        $method->setPrice(0);
        $method->setCost(0);

        if ($isFree) {
            $result->append($method);
        }

        return $result;
    }
}

