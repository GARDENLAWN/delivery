<?php
namespace GardenLawn\Delivery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class OtherRequirements implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            // Wymagania pojazdu
            ['value' => '1_hds', 'label' => __('HDS')],
            ['value' => '3_lift', 'label' => __('Lift (Winda)')],
            ['value' => '10_xl_certificate', 'label' => __('XL Certificate (Certyfikat XL)')],
            ['value' => '12_doppel_decker', 'label' => __('Doppel Decker (Podwójna podłoga)')],
            ['value' => '17_ramp_height', 'label' => __('Ramp Height (Wysokość rampowa)')],
            ['value' => '32_backup_generator_for_reefer', 'label' => __('Backup Generator for Reefer')],

            // Wymagania chłodnicze
            ['value' => '16_multi_temperature', 'label' => __('Multi-temperature')],
            ['value' => '22_temperature_log', 'label' => __('Temperature Log (Wydruk temperatury)')],
            ['value' => '23_load_securing_poles', 'label' => __('Load Securing Poles (Tyczki zabezpieczające)')],
            ['value' => '24_thermometer', 'label' => __('Thermometer')],

            // Wymagania celne
            ['value' => '4_custom_rope', 'label' => __('Custom Rope (Linka celna)')],
            ['value' => '6_goods_to_declare', 'label' => __('Goods to Declare (Świadectwo uznania celnego)')],
            ['value' => '8_carnet_tir', 'label' => __('Carnet TIR')],
            ['value' => '9_carnet_ata', 'label' => __('Carnet ATA')],
            ['value' => '19_autorisation_ecmt_cemt_licence', 'label' => __('ECMT/CEMT Licence')],

            // Wymagania bezpieczeństwa
            ['value' => '14_non-slip_mats', 'label' => __('Non-slip Mats (Maty antypoślizgowe)')],
            ['value' => '18_transport_lines', 'label' => __('Transport Lines (Pasy zabezpieczające)')],
            ['value' => '20_safety_bar', 'label' => __('Safety Bar (Sztaba zabezpieczająca)')],
            ['value' => '21_corner_protector', 'label' => __('Corner Protector (Narożniki)')],

            // Certyfikaty i pozwolenia
            ['value' => '7_adr', 'label' => __('ADR')],
            ['value' => '11_a-sign', 'label' => __('A-Sign (Odpady "A")')],
            ['value' => '13_gmp_certificate', 'label' => __('GMP+ Certificate')],
            ['value' => '25_qualimat_certificate', 'label' => __('Qualimat/Oqualim Certificate')],
            ['value' => '26_tascc_cerificate', 'label' => __('TASCC Certificate')],
            ['value' => '27_cleaning_certificate', 'label' => __('Cleaning Certificate')],
            ['value' => '28_waste_transport_italy_licence', 'label' => __('Waste Transport Italy (ALBO)')],
            ['value' => '29_abp_cat_1_permit', 'label' => __('ABP Cat 1 Permit')],
            ['value' => '30_abp_cat_2_permit', 'label' => __('ABP Cat 2 Permit')],
            ['value' => '31_abp_cat_3_permit', 'label' => __('ABP Cat 3 Permit')],

            // Pozostałe
            ['value' => '5_pallet_basket', 'label' => __('Pallet Basket (Kosz paletowy)')],
            ['value' => '15_double_cast', 'label' => __('Double Cast (2 kierowców)')],
        ];
    }
}
