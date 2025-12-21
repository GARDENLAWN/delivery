<?php

namespace GardenLawn\Delivery\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    const XML_PATH_ENABLED = 'delivery/general/enabled';
    const XML_PATH_API_KEY = 'delivery/general/google_maps_api_key';
    const XML_PATH_WAREHOUSE_ORIGIN = 'delivery/general/warehouse_origin';

    public function isEnabled($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getApiKey($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_API_KEY,
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
}
