<?php

namespace GardenLawn\Delivery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class AvoidFeatures implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'tollRoad', 'label' => __('Toll Roads')],
            ['value' => 'controlledAccessHighway', 'label' => __('Highways')],
            ['value' => 'ferry', 'label' => __('Ferries')],
            ['value' => 'tunnel', 'label' => __('Tunnels')],
            ['value' => 'dirtRoad', 'label' => __('Dirt Roads')],
            ['value' => 'uTurns', 'label' => __('U-Turns')]
        ];
    }
}
