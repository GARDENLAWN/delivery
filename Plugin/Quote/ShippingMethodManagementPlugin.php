<?php

namespace GardenLawn\Delivery\Plugin\Quote;

use Magento\Quote\Api\Data\ShippingMethodInterface;

class ShippingMethodManagementPlugin
{
    /**
     * Filter rates for guest estimate (GuestShippingMethodManagementInterface)
     *
     * @param mixed $subject
     * @param ShippingMethodInterface[] $result
     * @return ShippingMethodInterface[]
     */
    public function afterEstimateByExtendedAddress($subject, array $result): array
    {
        return $this->filterRates($result);
    }

    /**
     * Filter rates for logged in estimate (ShippingMethodManagementInterface)
     *
     * @param mixed $subject
     * @param ShippingMethodInterface[] $result
     * @return ShippingMethodInterface[]
     */
    public function afterEstimateByAddress($subject, array $result): array
    {
        return $this->filterRates($result);
    }

    /**
     * Filter rates for logged in estimate by ID (ShippingMethodManagementInterface)
     *
     * @param mixed $subject
     * @param ShippingMethodInterface[] $result
     * @return ShippingMethodInterface[]
     */
    public function afterEstimateByAddressId($subject, array $result): array
    {
        return $this->filterRates($result);
    }

    /**
     * Filter rates for getList (ShippingMethodManagementInterface)
     *
     * @param mixed $subject
     * @param ShippingMethodInterface[] $result
     * @return ShippingMethodInterface[]
     */
    public function afterGetList($subject, array $result): array
    {
        return $this->filterRates($result);
    }

    /**
     * Common filter logic
     *
     * @param ShippingMethodInterface[] $rates
     * @return ShippingMethodInterface[]
     */
    private function filterRates(array $rates): array
    {
        $distanceShippingRate = null;
        $directNoLiftRate = null;
        $distanceShippingKey = null;
        $directNoLiftKey = null;

        foreach ($rates as $key => $rate) {
            $carrier = $rate->getCarrierCode();

            if ($carrier === 'distanceshipping') {
                $distanceShippingRate = $rate;
                $distanceShippingKey = $key;
            } elseif ($carrier === 'direct_no_lift') {
                $directNoLiftRate = $rate;
                $directNoLiftKey = $key;
            }
        }

        if ($distanceShippingRate && $directNoLiftRate) {
            $priceDistance = $distanceShippingRate->getAmount();
            $priceDirect = $directNoLiftRate->getAmount();

            if ($priceDistance <= $priceDirect) {
                unset($rates[$directNoLiftKey]);
            } else {
                unset($rates[$distanceShippingKey]);
            }
        }

        return array_values($rates);
    }
}
