<?php

namespace GardenLawn\Delivery\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    const XML_PATH_ENABLED = 'delivery/general/enabled';
    const XML_PATH_WAREHOUSE_ORIGIN = 'delivery/general/warehouse_origin';

    const XML_PATH_PROVIDER = 'delivery/api_provider/provider';
    const XML_PATH_GOOGLE_API_KEY = 'delivery/api_provider/google_maps_api_key';
    const XML_PATH_HERE_API_KEY = 'delivery/api_provider/here_api_key';

    const XML_PATH_TRUCK_HEIGHT = 'delivery/truck_settings/vehicle_height';
    const XML_PATH_TRUCK_WIDTH = 'delivery/truck_settings/vehicle_width';
    const XML_PATH_TRUCK_LENGTH = 'delivery/truck_settings/vehicle_length';
    const XML_PATH_TRUCK_WEIGHT = 'delivery/truck_settings/vehicle_weight';
    const XML_PATH_TRUCK_AXLE_WEIGHT = 'delivery/truck_settings/vehicle_axle_weight';
    const XML_PATH_TRUCK_AXLE_COUNT = 'delivery/truck_settings/vehicle_axle_count';
    const XML_PATH_TRUCK_TYPE = 'delivery/truck_settings/vehicle_type';
    const XML_PATH_TRUCK_HAZARDOUS = 'delivery/truck_settings/hazardous_goods';
    const XML_PATH_TRUCK_AVOID = 'delivery/truck_settings/avoid_features';

    public function isEnabled($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getWarehouseOrigin($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_WAREHOUSE_ORIGIN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getProvider($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_PROVIDER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getGoogleApiKey($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_GOOGLE_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getHereApiKey($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_HERE_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getTruckParameters($storeId = null)
    {
        return [
            'height' => $this->scopeConfig->getValue(self::XML_PATH_TRUCK_HEIGHT, ScopeInterface::SCOPE_STORE, $storeId),
            'width' => $this->scopeConfig->getValue(self::XML_PATH_TRUCK_WIDTH, ScopeInterface::SCOPE_STORE, $storeId),
            'length' => $this->scopeConfig->getValue(self::XML_PATH_TRUCK_LENGTH, ScopeInterface::SCOPE_STORE, $storeId),
            'grossWeight' => $this->scopeConfig->getValue(self::XML_PATH_TRUCK_WEIGHT, ScopeInterface::SCOPE_STORE, $storeId),
            'weightPerAxle' => $this->scopeConfig->getValue(self::XML_PATH_TRUCK_AXLE_WEIGHT, ScopeInterface::SCOPE_STORE, $storeId),
            'axleCount' => $this->scopeConfig->getValue(self::XML_PATH_TRUCK_AXLE_COUNT, ScopeInterface::SCOPE_STORE, $storeId),
            'type' => $this->scopeConfig->getValue(self::XML_PATH_TRUCK_TYPE, ScopeInterface::SCOPE_STORE, $storeId),
            'hazardousGoods' => $this->scopeConfig->getValue(self::XML_PATH_TRUCK_HAZARDOUS, ScopeInterface::SCOPE_STORE, $storeId),
            'avoid' => $this->scopeConfig->getValue(self::XML_PATH_TRUCK_AVOID, ScopeInterface::SCOPE_STORE, $storeId),
        ];
    }
}
