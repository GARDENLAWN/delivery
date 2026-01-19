<?php
namespace GardenLawn\Delivery\Block\Adminhtml\System\Config\Form\Field;

use Magento\Framework\View\Element\Html\Select;
use GardenLawn\Delivery\Model\Config\Source\FreightType;

class FreightTypeColumn extends Select
{
    protected $freightTypeSource;

    public function __construct(
        \Magento\Framework\View\Element\Context $context,
        FreightType $freightTypeSource,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->freightTypeSource = $freightTypeSource;
    }

    public function setInputName($value)
    {
        return $this->setName($value);
    }

    public function _toHtml()
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->freightTypeSource->toOptionArray());
        }
        $this->setExtraParams('style="width: 120px;"');
        return parent::_toHtml();
    }
}
