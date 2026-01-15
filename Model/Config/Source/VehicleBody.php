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
            ['value' => '41_bde', 'label' => __('BDE')],
            ['value' => '11_coilmulde', 'label' => __('Coilmulde')],
            ['value' => '40_joloda', 'label' => __('Joloda')],
            ['value' => '48_jumbo', 'label' => __('Jumbo')],
            ['value' => '10_mega', 'label' => __('Mega')],

            // Sztywna zabudowa
            ['value' => '42_open_box', 'label' => __('Open Box (Skrzynia)')],
            ['value' => '19_box', 'label' => __('Box (Sztywna zabudowa)')],

            // Chłodnia
            ['value' => '1_cooler', 'label' => __('Cooler (Chłodnia)')],
            ['value' => '43_meathanging', 'label' => __('Meat Hanging (Hakówka)')],
            ['value' => '2_isotherm', 'label' => __('Isotherm (Izoterma)')],

            // Cysterna
            ['value' => '5_chemical_tanker', 'label' => __('Chemical Tanker (Cysterna chemiczna)')],
            ['value' => '6_gas_tanker', 'label' => __('Gas Tanker (Cysterna gazowa)')],
            ['value' => '4_fuel_tanker', 'label' => __('Fuel Tanker (Cysterna paliwowa)')],
            ['value' => '3_food_tanker', 'label' => __('Food Tanker (Cysterna spożywcza)')],
            ['value' => '47_other_tanker', 'label' => __('Other Tanker (Inna cysterna)')],
            ['value' => '7_silos', 'label' => __('Silos')],

            // Wywrotka
            ['value' => '44_walkingfloor', 'label' => __('Walkingfloor')],
            ['value' => '20_dump_truck', 'label' => __('Dump Truck (Wywrotka)')],
            ['value' => '49_steel_tipper_truck', 'label' => __('Steel Tipper (Wywrotka stalowa)')],
            ['value' => '50_aluminum_tipper_truck', 'label' => __('Aluminum Tipper (Wywrotka aluminiowa)')],

            // Podkontenerowa
            ['value' => '45_tank_body_20', 'label' => __('20\' Tank Body (20\' cysterna)')],
            ['value' => '37_20_standard', 'label' => __('20\' Standard')],
            ['value' => '46_tank_body_40', 'label' => __('40\' Tank Body (40\' cysterna)')],
            ['value' => '38_40_standard', 'label' => __('40\' Standard')],
            ['value' => '39_45_standard', 'label' => __('45\' Standard')],
            ['value' => '21_swapbody', 'label' => __('Swapbody')],

            // Dowolna / Inne
            ['value' => '18_truck', 'label' => __('Truck (Ciągnik siodłowy)')],
            ['value' => '12_log_trailer', 'label' => __('Log Trailer (Dłużyca)')],
            ['value' => '16_hook_truck', 'label' => __('Hook Truck (Hakowiec)')],
            ['value' => '15_other', 'label' => __('Other (Inne)')],
            ['value' => '14_tow_truck', 'label' => __('Tow Truck (Laweta)')],
            ['value' => '17_low_loader', 'label' => __('Low Loader (Niskopodwoziowy)')],
            ['value' => '13_platform_trailer', 'label' => __('Platform Trailer (Platforma)')],
        ];
    }
}
