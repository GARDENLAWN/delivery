<?php
namespace GardenLawn\Delivery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class LoadType implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            // Palety
            ['value' => '2_europalette', 'label' => __('Europallet (120 x 80 cm)')],
            ['value' => '1_palette', 'label' => __('Custom Pallet (Paleta niestandardowa)')],
            ['value' => '34_eur_6', 'label' => __('EUR 6 Pallet (80 x 60 cm)')],
            ['value' => '35_eur_2', 'label' => __('EUR 2 Pallet (120 x 100 cm)')],
            ['value' => '36_eur_3', 'label' => __('EUR 3 Pallet (100 x 120 cm)')],
            ['value' => '37_container_palette', 'label' => __('Container Pallet (114 x 114 cm)')],
            ['value' => '38_oversized', 'label' => __('Oversized Pallet (120 x 120 cm)')],

            // Inne typy
            ['value' => '3_big_bag', 'label' => __('Big Bag (90 x 90 cm)')],
            ['value' => '4_log', 'label' => __('Log (Dłużyca)')],
            ['value' => '5_bag', 'label' => __('Bag (Worek 90 x 90 cm)')],
            ['value' => '6_barrel', 'label' => __('Barrel (Beczka)')],
            ['value' => '7_carton', 'label' => __('Carton (Karton)')],
            ['value' => '8_piece', 'label' => __('Piece (Sztuka)')],
            ['value' => '9_cubic', 'label' => __('Bulk Materials (Materiały sypkie)')],
            ['value' => '10_box', 'label' => __('Box (Skrzynia)')],
            ['value' => '11_other', 'label' => __('Other (Inny)')],
            ['value' => '12_roll', 'label' => __('Roll (Rolka)')],
            ['value' => '11_crates', 'label' => __('Crates (Skrzyniopaleta)')], // Zachowane ze starej listy, jeśli używane
            ['value' => '23_machinery', 'label' => __('Machinery (Maszyny)')], // Zachowane
            ['value' => '24_oversize', 'label' => __('Oversize (Gabaryt)')], // Zachowane

            // Palety chemiczne
            ['value' => '13_cp1_chemical_palette', 'label' => __('CP1 Chemical Pallet (100 x 120 cm)')],
            ['value' => '14_cp2_chemical_palette', 'label' => __('CP2 Chemical Pallet (80 x 120 cm)')],
            ['value' => '15_cp3_chemical_palette', 'label' => __('CP3 Chemical Pallet (114 x 114 cm)')],
            ['value' => '16_cp4_chemical_palette', 'label' => __('CP4 Chemical Pallet (111 x 130 cm)')],
            ['value' => '17_cp5_chemical_palette', 'label' => __('CP5 Chemical Pallet (76 x 114 cm)')],
            ['value' => '18_cp6_chemical_palette', 'label' => __('CP6 Chemical Pallet (100 x 120 cm)')],
            ['value' => '19_cp7_chemical_palette', 'label' => __('CP7 Chemical Pallet (111 x 130 cm)')],
            ['value' => '20_cp8_chemical_palette', 'label' => __('CP8 Chemical Pallet (114 x 114 cm)')],
            ['value' => '21_cp9_chemical_palette', 'label' => __('CP9 Chemical Pallet (114 x 114 cm)')],

            // Kontenery
            ['value' => '22_20gp_dry_van', 'label' => __('20GP Dry Van')],
            ['value' => '23_40gp_dry_van', 'label' => __('40GP Dry Van')],
            ['value' => '24_40hc_high_cube', 'label' => __('40HC High Cube')],
            ['value' => '25_45hc_high_cube', 'label' => __('45HC High Cube')],
            ['value' => '26_20re_temperature_controlled', 'label' => __('20RE Reefer')],
            ['value' => '27_40re_temperature_controlled', 'label' => __('40RE Reefer')],
            ['value' => '28_40rh_temperature_controlled_high_cube', 'label' => __('40RH Reefer High Cube')],
            ['value' => '29_20ot_open_top', 'label' => __('20OT Open Top')],
            ['value' => '30_40ot_open_top', 'label' => __('40OT Open Top')],
            ['value' => '31_40hw_palette_high_cube', 'label' => __('40HW Pallet High Cube')],
            ['value' => '32_45hw_palette_high_cube', 'label' => __('45HW Pallet High Cube')],
            ['value' => '33_20vh_ventilated_container', 'label' => __('20VH Ventilated')],
        ];
    }
}
