<?php

namespace GardenLawn\Delivery\Plugin\Quote;

use Magento\Quote\Api\Data\ShippingMethodExtensionFactory;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Model\Cart\ShippingMethodConverter;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\SalesRule\Model\Rule;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Quote\Model\Quote\Address\Rate;

class ShippingMethodConverterPlugin
{
    private ShippingMethodExtensionFactory $extensionFactory;
    private ScopeConfigInterface $scopeConfig;
    private RuleCollectionFactory $ruleCollectionFactory;
    private StoreManagerInterface $storeManager;
    private CustomerSession $customerSession;

    public function __construct(
        ShippingMethodExtensionFactory $extensionFactory,
        ScopeConfigInterface $scopeConfig,
        RuleCollectionFactory $ruleCollectionFactory,
        StoreManagerInterface $storeManager,
        CustomerSession $customerSession
    ) {
        $this->extensionFactory = $extensionFactory;
        $this->scopeConfig = $scopeConfig;
        $this->ruleCollectionFactory = $ruleCollectionFactory;
        $this->storeManager = $storeManager;
        $this->customerSession = $customerSession;
    }

    /**
     * Add description and promotion message to shipping method extension attributes
     *
     * @param ShippingMethodConverter $subject
     * @param ShippingMethodInterface $result
     * @param Rate $rateModel
     * @param string $quoteCurrencyCode
     * @return ShippingMethodInterface
     */
    public function afterModelToDataObject(
        ShippingMethodConverter $subject,
        ShippingMethodInterface $result,
        $rateModel,
        $quoteCurrencyCode
    ): ShippingMethodInterface {
        $extensionAttributes = $result->getExtensionAttributes();
        if ($extensionAttributes === null) {
            $extensionAttributes = $this->extensionFactory->create();
        }

        // 1. Add Description
        $carrierCode = $result->getCarrierCode();
        $description = $this->scopeConfig->getValue(
            'carriers/' . $carrierCode . '/description',
            ScopeInterface::SCOPE_STORE
        );

        if ($description) {
            $extensionAttributes->setDescription($description);
        }

        // 2. Add Promotion Message
        try {
            $address = $rateModel->getAddress();
            if ($address) {
                // We need to clone address to safely modify it for validation (set specific shipping method)
                // However, cloning might lose cached items.
                // But here we are inside the loop of rate collection, so address is "live".
                // We should be careful not to modify the main address object state permanently.

                // Better: Create a temporary address object for validation
                // Or just use the address but restore state?
                // Restoring state is risky.

                // Let's try cloning and fixing items like we did before.
                $validationAddress = clone $address;

                // Fix items on cloned address
                if ($address->getAllItems()) {
                    $validationAddress->setData('all_items', $address->getAllItems());
                    $validationAddress->setData('cached_items_all', $address->getAllItems());
                }

                // Ensure quote is set
                $quote = $address->getQuote();
                if ($quote && method_exists($validationAddress, 'setQuote')) {
                    $validationAddress->setQuote($quote);
                }

                // Set current method being converted
                $methodCode = $result->getCarrierCode() . '_' . $result->getMethodCode();
                $validationAddress->setShippingMethod($methodCode);
                $validationAddress->setCollectShippingRates(true);

                $messages = $this->getPromotionMessages($validationAddress);

                if (!empty($messages)) {
                    $extensionAttributes->setPromotionMessage(implode('<br>', $messages));
                }
            }
        } catch (\Exception $e) {
            // Log error if needed
        }

        $result->setExtensionAttributes($extensionAttributes);

        return $result;
    }

    private function getPromotionMessages($address)
    {
        $websiteId = $this->storeManager->getStore()->getWebsiteId();
        $customerGroupId = $this->customerSession->getCustomerGroupId();

        // Always load fresh rules collection to avoid side effects of modification (removing conditions)
        $rules = $this->ruleCollectionFactory->create()
            ->setValidationFilter($websiteId, $customerGroupId)
            ->addFieldToFilter('is_active', 1)
            ->addFieldToFilter('coupon_type', Rule::COUPON_TYPE_NO_COUPON);

        foreach ($rules as $rule) {
            $rule->afterLoad();
        }

        $messages = [];
        foreach ($rules as $rule) {
            // Extract qty requirement
            $qtyRequirement = $this->extractQtyRequirement($rule->getConditions());

            // Remove qty conditions (on the fly modification of cached rule - safe for this request context)
            $this->removeQtyConditions($rule->getConditions());

            if ($rule->validate($address)) {
                $message = $rule->getDescription() ?: $rule->getName();
                if ($qtyRequirement) {
                    $message .= ' (' . __('buy: %1 m²', $qtyRequirement) . ')';
                }
                $messages[] = $message;
            }
        }
        return $messages;
    }

    private function removeQtyConditions($combine)
    {
        $conditions = $combine->getConditions();
        $newConditions = [];
        foreach ($conditions as $condition) {
            if ($condition instanceof \Magento\SalesRule\Model\Rule\Condition\Combine) {
                $this->removeQtyConditions($condition);
                $newConditions[] = $condition;
            } elseif ($condition instanceof \Magento\SalesRule\Model\Rule\Condition\Address) {
                if ($condition->getAttribute() !== 'total_qty') {
                    $newConditions[] = $condition;
                }
            } else {
                $newConditions[] = $condition;
            }
        }
        $combine->setConditions($newConditions);
    }

    private function extractQtyRequirement($combine)
    {
        foreach ($combine->getConditions() as $condition) {
            if ($condition instanceof \Magento\SalesRule\Model\Rule\Condition\Combine) {
                $qty = $this->extractQtyRequirement($condition);
                if ($qty) return $qty;
            } elseif ($condition instanceof \Magento\SalesRule\Model\Rule\Condition\Address) {
                if ($condition->getAttribute() === 'total_qty') {
                    return (float)$condition->getValue();
                }
            }
        }
        return null;
    }
}
