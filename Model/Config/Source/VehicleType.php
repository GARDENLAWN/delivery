<?php

namespace GardenLawn\Delivery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class VehicleType implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'straightTruck', 'label' => __('Straight Truck')],
            ['value' => 'tractor', 'label' => __('Tractor')]
        ];
    }
}
