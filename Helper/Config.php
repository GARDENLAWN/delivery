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

    // Alias for backward compatibility if needed, but prefer specific methods
    public function getApiKey($storeId = null)
    {
        $provider = $this->getProvider($storeId);
        if ($provider === 'here') {
            return $this->getHereApiKey($storeId);
        }
        return $this->getGoogleApiKey($storeId);
    }

    public function getHereApiKey($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_HERE_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
