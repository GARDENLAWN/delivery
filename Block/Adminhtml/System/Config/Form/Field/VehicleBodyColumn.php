<?php
namespace GardenLawn\Delivery\Block\Adminhtml\System\Config\Form\Field;

use Magento\Framework\View\Element\Html\Select;
use GardenLawn\Delivery\Model\Config\Source\VehicleBody;

class VehicleBodyColumn extends Select
{
    protected $vehicleBodySource;

    public function __construct(
        \Magento\Framework\View\Element\Context $context,
        VehicleBody $vehicleBodySource,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->vehicleBodySource = $vehicleBodySource;
    }

    public function setInputName($value)
    {
        return $this->setName($value . '[]');
    }

    public function _toHtml()
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->vehicleBodySource->toOptionArray());
        }
        $this->setExtraParams('multiple="multiple" size="5" style="width: 250px;"');
        return parent::_toHtml();
    }
}
