<?php
namespace GardenLawn\Delivery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class LoadType implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => '2_europalette', 'label' => __('Europallet (120x80)')],
            ['value' => '1_other_palette', 'label' => __('Other Pallet')],
            ['value' => '10_box', 'label' => __('Box (Karton)')],
            ['value' => '11_crates', 'label' => __('Crates (Skrzyniopaleta)')],
            ['value' => '19_big_bag', 'label' => __('Big Bag')],
            ['value' => '20_long_load', 'label' => __('Long Load (Dłużyca)')],
            ['value' => '23_machinery', 'label' => __('Machinery (Maszyny)')],
            ['value' => '24_oversize', 'label' => __('Oversize (Gabaryt)')],
        ];
    }
}
