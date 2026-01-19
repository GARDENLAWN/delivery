<?php
namespace GardenLawn\Delivery\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;

class PricingConfig extends AbstractFieldArray
{
    protected $typeRenderer;

    protected function _prepareToRender()
    {
        $this->addColumn('min_distance', [
            'label' => __('Min Distance (km)'),
            'class' => 'required-entry validate-number',
            'style' => 'width: 100px'
        ]);
        $this->addColumn('price', [
            'label' => __('Price'),
            'class' => 'required-entry validate-number',
            'style' => 'width: 100px'
        ]);
        $this->addColumn('type', [
            'label' => __('Type'),
            'renderer' => $this->getTypeRenderer(),
            'style' => 'width: 120px'
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Tier');
    }

    /**
     * Prepare existing row data object
     * Handles compatibility with old JSON format {"tiers": [...]}
     *
     * @return array
     */
    public function getArrayRows()
    {
        $element = $this->getElement();
        $value = $element->getValue();

        // Fix for old JSON format {"tiers": [...]}
        if (is_array($value) && isset($value['tiers']) && is_array($value['tiers'])) {
            $newValue = [];
            foreach ($value['tiers'] as $tier) {
                // Generate a unique ID for the row to satisfy ArraySerialized requirements
                $id = '_row_' . uniqid();
                $newValue[$id] = $tier;
            }
            $element->setValue($newValue);
        }

        return parent::getArrayRows();
    }

    protected function getTypeRenderer()
    {
        if (!$this->typeRenderer) {
            $this->typeRenderer = $this->getLayout()->createBlock(
                \GardenLawn\Delivery\Block\Adminhtml\System\Config\Form\Field\PricingTypeColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->typeRenderer;
    }

    protected function _prepareArrayRow(DataObject $row)
    {
        $options = [];
        $type = $row->getType();
        if ($type !== null) {
            $options['option_' . $this->getTypeRenderer()->calcOptionHash($type)] = 'selected="selected"';
        }
        $row->setData('option_extra_attrs', $options);
    }
}
