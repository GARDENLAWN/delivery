<?php
namespace GardenLawn\Delivery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class VehicleBody implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => '9_curtainsider', 'label' => __('Curtainsider (Firanka)')],
            ['value' => '8_standard_tent', 'label' => __('Standard Tent (Plandeka)')],
            ['value' => '1_box', 'label' => __('Box (Kontener)')],
            ['value' => '2_isotherm', 'label' => __('Isotherm (Izoterma)')],
            ['value' => '3_cooler', 'label' => __('Cooler (Chłodnia)')],
            ['value' => '12_mega', 'label' => __('Mega')],
            ['value' => '17_open', 'label' => __('Open (Odkryty)')],
            ['value' => '18_tipper', 'label' => __('Tipper (Wywrotka)')],
            ['value' => '21_platform', 'label' => __('Platform (Platforma)')],
            ['value' => '22_low_loader', 'label' => __('Low Loader (Niskopodwoziowy)')],
        ];
    }
}
