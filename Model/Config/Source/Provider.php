<?php

namespace GardenLawn\Delivery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Provider implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'google', 'label' => __('Google Maps')],
            ['value' => 'here', 'label' => __('HERE Technologies')]
        ];
    }
}
