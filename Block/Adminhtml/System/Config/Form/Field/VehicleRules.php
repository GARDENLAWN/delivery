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
            'class' => 'required-entry validate-digits',
            'style' => 'width: 60px'
        ]);
        $this->addColumn('m2_per_pallet', [
            'label' => __('m2 / Pallet'),
            'class' => 'required-entry validate-number',
            'style' => 'width: 60px'
        ]);
        $this->addColumn('pallet_length', [
            'label' => __('Pallet Length (m)'),
            'class' => 'validate-number',
            'style' => 'width: 60px'
        ]);
        $this->addColumn('pallet_width', [
            'label' => __('Pallet Width (m)'),
            'class' => 'validate-number',
            'style' => 'width: 60px'
        ]);
        $this->addColumn('vehicle_size', [
            'label' => __('Vehicle Size'),
            'renderer' => $this->getVehicleSizeRenderer(),
            'style' => 'width: 180px'
        ]);
        $this->addColumn('vehicle_bodies', [
            'label' => __('Vehicle Body'),
            'renderer' => $this->getVehicleBodyRenderer(),
            'style' => 'width: 180px'
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Rule');
    }

    /**
     * Prepare existing row data object
     * This fixes the "ReferenceError" by ensuring all keys exist
     * And fixes "not a valid selector" by ensuring IDs are not numeric
     *
     * @return array
     */
    public function getArrayRows()
    {
        $result = parent::getArrayRows();
        $newResult = [];

        foreach ($result as $rowId => $row) {
            // Ensure the key exists to prevent JS error
            if (!$row->hasData('m2_per_pallet')) {
                $row->setData('m2_per_pallet', '35'); // Default value
            }
            if (!$row->hasData('pallet_length')) {
                $row->setData('pallet_length', '1.2'); // Default Europallet
            }
            if (!$row->hasData('pallet_width')) {
                $row->setData('pallet_width', '0.8'); // Default Europallet
            }

            // Ensure other keys exist too just in case
            if (!$row->hasData('max_pallets')) {
                $row->setData('max_pallets', '');
            }

            // Fix for "not a valid selector" error when ID is numeric (e.g. "0")
            // We prefix it with "row_" to make it a valid CSS ID
            if (is_numeric($rowId)) {
                $newRowId = 'row_' . $rowId;
                $row->setData('_id', $newRowId);
                $newResult[$newRowId] = $row;
            } else {
                $newResult[$rowId] = $row;
            }
        }
        return $newResult;
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
            $selectedSizes = is_array($vehicleSize) ? $vehicleSize : explode(',', (string)$vehicleSize);
            foreach ($selectedSizes as $size) {
                if (is_string($size) || is_numeric($size)) {
                    $options['option_' . $this->getVehicleSizeRenderer()->calcOptionHash($size)] = 'selected="selected"';
                }
            }
        }

        $vehicleBody = $row->getVehicleBodies();
        if ($vehicleBody !== null) {
            // Handle potential array if it was saved as such
            if (is_array($vehicleBody)) {
                foreach ($vehicleBody as $body) {
                    if (is_string($body) || is_numeric($body)) {
                        $options['option_' . $this->getVehicleBodyRenderer()->calcOptionHash($body)] = 'selected="selected"';
                    }
                }
            } else {
                $options['option_' . $this->getVehicleBodyRenderer()->calcOptionHash($vehicleBody)] = 'selected="selected"';
            }
        }

        $row->setData('option_extra_attrs', $options);
    }
}
