<?php
namespace GardenLawn\Delivery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class FreightType implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'ftl', 'label' => __('FTL (Full Truck Load)')],
            ['value' => 'ltl', 'label' => __('LTL (Less Than Truck Load)')],
        ];
    }
}
