<?php

namespace GardenLawn\Delivery\Plugin\Quote;

use Magento\Quote\Api\Data\ShippingMethodExtensionFactory;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Model\Cart\ShippingMethodConverter;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class ShippingMethodConverterPlugin
{
    private ShippingMethodExtensionFactory $extensionFactory;
    private ScopeConfigInterface $scopeConfig;

    public function __construct(
        ShippingMethodExtensionFactory $extensionFactory,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->extensionFactory = $extensionFactory;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Add description to shipping method extension attributes
     *
     * @param ShippingMethodConverter $subject
     * @param ShippingMethodInterface $result
     * @param string $quoteCurrencyCode
     * @return ShippingMethodInterface
     */
    public function afterModelToDataObject(
        ShippingMethodConverter $subject,
        ShippingMethodInterface $result,
        $quoteCurrencyCode
    ): ShippingMethodInterface {
        $carrierCode = $result->getCarrierCode();

        // Get description from config
        // Path: carriers/<carrier_code>/description
        $description = $this->scopeConfig->getValue(
            'carriers/' . $carrierCode . '/description',
            ScopeInterface::SCOPE_STORE
        );

        if ($description) {
            $extensionAttributes = $result->getExtensionAttributes();
            if ($extensionAttributes === null) {
                $extensionAttributes = $this->extensionFactory->create();
            }

            $extensionAttributes->setDescription($description);
            $result->setExtensionAttributes($extensionAttributes);
        }

        return $result;
    }
}
