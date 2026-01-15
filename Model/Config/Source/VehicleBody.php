<?php
namespace GardenLawn\Delivery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class VehicleBody implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            // Plandeka
            ['value' => '9_curtainsider', 'label' => __('Curtainsider (Firanka)')],
            ['value' => '8_standard_tent', 'label' => __('Standard Tent (Plandeka)')],

            // Sztywna zabudowa
            ['value' => '42_open_box', 'label' => __('Open Box (Skrzynia)')],
            ['value' => '19_box', 'label' => __('Box (Sztywna zabudowa)')],

            // Chłodnia
            ['value' => '1_cooler', 'label' => __('Cooler (Chłodnia)')],
            ['value' => '43_meathanging', 'label' => __('Meat Hanging (Hakówka)')],
            ['value' => '2_isotherm', 'label' => __('Isotherm (Izoterma)')],

            // Dowolna / Inne
            ['value' => '47_other_tanker', 'label' => __('Other Tanker (Inna cysterna)')],
            ['value' => '15_other', 'label' => __('Other (Inne)')],
            ['value' => '14_tow_truck', 'label' => __('Tow Truck (Laweta)')],
            ['value' => '20_dump_truck', 'label' => __('Dump Truck (Wywrotka)')],
            ['value' => '49_steel_tipper_truck', 'label' => __('Steel Tipper (Wywrotka stalowa)')],
            ['value' => '50_aluminum_tipper_truck', 'label' => __('Aluminum Tipper (Wywrotka aluminiowa)')],

            // Pozostałe z poprzedniej listy (dla kompatybilności, jeśli używane)
            ['value' => '12_mega', 'label' => __('Mega')],
            ['value' => '17_open', 'label' => __('Open (Odkryty)')],
            ['value' => '21_platform', 'label' => __('Platform (Platforma)')],
            ['value' => '22_low_loader', 'label' => __('Low Loader (Niskopodwoziowy)')],
        ];
    }
}
