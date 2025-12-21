<?php

namespace GardenLawn\Delivery\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class HazardousGoods implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'explosive', 'label' => __('Explosive')],
            ['value' => 'gas', 'label' => __('Gas')],
            ['value' => 'flammable', 'label' => __('Flammable')],
            ['value' => 'combustible', 'label' => __('Combustible')],
            ['value' => 'organic', 'label' => __('Organic')],
            ['value' => 'poison', 'label' => __('Poison')],
            ['value' => 'radioactive', 'label' => __('Radioactive')],
            ['value' => 'corrosive', 'label' => __('Corrosive')],
            ['value' => 'poisonousInhalation', 'label' => __('Poisonous Inhalation')],
            ['value' => 'harmfulToWater', 'label' => __('Harmful to Water')],
            ['value' => 'other', 'label' => __('Other')]
        ];
    }
}
