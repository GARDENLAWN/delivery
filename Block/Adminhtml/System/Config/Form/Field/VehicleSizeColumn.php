<?php
namespace GardenLawn\Delivery\Block\Adminhtml\System\Config\Form\Field;

use Magento\Framework\View\Element\Html\Select;
use GardenLawn\Delivery\Model\Config\Source\VehicleSize;

class VehicleSizeColumn extends Select
{
    protected $vehicleSizeSource;

    public function __construct(
        \Magento\Framework\View\Element\Context $context,
        VehicleSize $vehicleSizeSource,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->vehicleSizeSource = $vehicleSizeSource;
    }

    public function setInputName($value)
    {
        return $this->setName($value . '[]');
    }

    public function _toHtml()
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->vehicleSizeSource->toOptionArray());
        }
        $this->setExtraParams('multiple="multiple" size="5" style="width: 180px;"');
        return parent::_toHtml();
    }
}
