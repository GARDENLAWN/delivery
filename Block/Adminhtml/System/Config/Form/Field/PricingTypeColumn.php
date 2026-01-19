<?php
namespace GardenLawn\Delivery\Block\Adminhtml\System\Config\Form\Field;

use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;
use GardenLawn\Delivery\Model\Config\Source\PricingType;

class PricingTypeColumn extends Select
{
    protected PricingType $pricingTypeSource;

    public function __construct(
        Context $context,
        PricingType $pricingTypeSource,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->pricingTypeSource = $pricingTypeSource;
    }

    public function setInputName($value)
    {
        return $this->setName($value);
    }

    public function _toHtml()
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->pricingTypeSource->toOptionArray());
        }
        return parent::_toHtml();
    }
}
