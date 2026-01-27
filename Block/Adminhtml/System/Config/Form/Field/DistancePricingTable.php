<?php
namespace GardenLawn\Delivery\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

class DistancePricingTable extends AbstractFieldArray
{
    protected function _prepareToRender()
    {
        $this->addColumn('m2', [
            'label' => __('Metry Kwadratowe (m2)'),
            'class' => 'required-entry validate-number',
            'style' => 'width: 100px'
        ]);
        $this->addColumn('price', [
            'label' => __('Cena za m2 (PLN)'),
            'class' => 'required-entry validate-number',
            'style' => 'width: 100px'
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Dodaj Próg');
    }
}
