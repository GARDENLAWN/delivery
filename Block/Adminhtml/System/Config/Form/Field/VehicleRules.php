<?php
namespace GardenLawn\Delivery\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

class VehicleRules extends AbstractFieldArray
{
    protected $vehicleSizeRenderer;
    protected $vehicleBodyRenderer;

    protected function _prepareToRender()
    {
        $this->addColumn('max_pallets', [
            'label' => __('Max Pallets'),
            'class' => 'required-entry validate-digits'
        ]);
        $this->addColumn('vehicle_size', [
            'label' => __('Vehicle Size'),
            'renderer' => $this->getVehicleSizeRenderer()
        ]);
        $this->addColumn('vehicle_bodies', [
            'label' => __('Vehicle Body'),
            'renderer' => $this->getVehicleBodyRenderer()
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Rule');
    }

    protected function getVehicleSizeRenderer()
    {
        if (!$this->vehicleSizeRenderer) {
            $this->vehicleSizeRenderer = $this->getLayout()->createBlock(
                \GardenLawn\Delivery\Block\Adminhtml\System\Config\Form\Field\VehicleSizeColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->vehicleSizeRenderer;
    }

    protected function getVehicleBodyRenderer()
    {
        if (!$this->vehicleBodyRenderer) {
            $this->vehicleBodyRenderer = $this->getLayout()->createBlock(
                \GardenLawn\Delivery\Block\Adminhtml\System\Config\Form\Field\VehicleBodyColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->vehicleBodyRenderer;
    }

    protected function _prepareArrayRow(DataObject $row)
    {
        $options = [];

        $vehicleSize = $row->getVehicleSize();
        if ($vehicleSize !== null) {
            // Handle multiselect array or single value
            $selectedSizes = is_array($vehicleSize) ? $vehicleSize : explode(',', $vehicleSize);
            foreach ($selectedSizes as $size) {
                $options['option_' . $this->getVehicleSizeRenderer()->calcOptionHash($size)] = 'selected="selected"';
            }
        }

        $vehicleBody = $row->getVehicleBodies();
        if ($vehicleBody !== null) {
            $options['option_' . $this->getVehicleBodyRenderer()->calcOptionHash($vehicleBody)] = 'selected="selected"';
        }

        $row->setData('option_extra_attrs', $options);
    }
}
