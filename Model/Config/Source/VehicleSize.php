<?php
namespace GardenLawn\Delivery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class VehicleSize implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => '3_lorry', 'label' => __('Semi-trailer (Tir)')],
            ['value' => '5_solo', 'label' => __('Solo (3.5t - 12t)')],
            ['value' => '2_double_trailer', 'label' => __('Truck with trailer (Zestaw)')],
        ];
    }
}
