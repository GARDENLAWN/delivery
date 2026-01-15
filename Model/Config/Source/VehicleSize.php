<?php
namespace GardenLawn\Delivery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class VehicleSize implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => '1_bus', 'label' => __('Bus (<= 3.5t)')],
            ['value' => '5_solo', 'label' => __('Solo (3.5t - 12t)')],
            ['value' => '3_lorry', 'label' => __('Semi-trailer (Tir)')],
            ['value' => '2_double_trailer', 'label' => __('Truck with trailer (Zestaw)')],
            ['value' => '13_bus_lorry_solo', 'label' => __('Bus / Solo (Any)')],
            ['value' => '14_double_trailer_lorry_solo', 'label' => __('Solo / Double Trailer (Any)')],
        ];
    }
}
