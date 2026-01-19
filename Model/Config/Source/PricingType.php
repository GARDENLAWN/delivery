<?php
namespace GardenLawn\Delivery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PricingType implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'fixed', 'label' => __('Fixed Price')],
            ['value' => 'per_km', 'label' => __('Price Per KM')],
        ];
    }
}
