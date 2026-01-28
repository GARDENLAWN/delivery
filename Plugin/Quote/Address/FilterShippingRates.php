<?php

namespace GardenLawn\Delivery\Plugin\Quote\Address;

use Magento\Quote\Model\Quote\Address;

class FilterShippingRates
{
    /**
     * Filter shipping rates to keep only the cheaper option between
     * 'distanceshipping' and 'direct_no_lift'.
     *
     * @param Address $subject
     * @param array $result Array of \Magento\Quote\Model\Quote\Address\Rate
     * @return array
     */
    public function afterGetAllShippingRates(Address $subject, array $result): array
    {
        return $this->filterRates($result);
    }

    /**
     * Filter grouped shipping rates.
     * Structure: ['carrier_code' => [rate1, rate2], ...]
     *
     * @param Address $subject
     * @param array $result
     * @return array
     */
    public function afterGetGroupedAllShippingRates(Address $subject, array $result): array
    {
        // Flatten the rates to find our targets easily
        $flatRates = [];
        foreach ($result as $carrierRates) {
            foreach ($carrierRates as $rate) {
                $flatRates[] = $rate;
            }
        }

        // Use the common filter logic to decide which one to keep
        // But we need to know WHICH one to remove from the grouped structure.

        $distanceShippingRate = null;
        $directNoLiftRate = null;

        // Find rates in the grouped structure
        // Note: distanceshipping is usually in 'distanceshipping' group
        // direct_no_lift is usually in 'direct_no_lift' group

        if (isset($result['distanceshipping'])) {
            foreach ($result['distanceshipping'] as $rate) {
                if ($rate->getMethod() === 'distanceshipping') {
                    $distanceShippingRate = $rate;
                    break;
                }
            }
        }

        if (isset($result['direct_no_lift'])) {
            foreach ($result['direct_no_lift'] as $rate) {
                if ($rate->getMethod() === 'direct_no_lift') {
                    $directNoLiftRate = $rate;
                    break;
                }
            }
        }

        if ($distanceShippingRate && $directNoLiftRate) {
            $priceDistance = $distanceShippingRate->getPrice();
            $priceDirect = $directNoLiftRate->getPrice();

            if ($priceDistance <= $priceDirect) {
                // Remove DirectNoLift group (assuming 1 method per carrier for these custom carriers)
                unset($result['direct_no_lift']);
            } else {
                // Remove DistanceShipping group
                unset($result['distanceshipping']);
            }
        }

        return $result;
    }

    /**
     * Common filter logic for flat array of rates
     */
    private function filterRates(array $rates): array
    {
        $distanceShippingRate = null;
        $directNoLiftRate = null;
        $distanceShippingKey = null;
        $directNoLiftKey = null;

        foreach ($rates as $key => $rate) {
            $carrier = $rate->getCarrier();

            if ($carrier === 'distanceshipping') {
                $distanceShippingRate = $rate;
                $distanceShippingKey = $key;
            } elseif ($carrier === 'direct_no_lift') {
                $directNoLiftRate = $rate;
                $directNoLiftKey = $key;
            }
        }

        if ($distanceShippingRate && $directNoLiftRate) {
            $priceDistance = $distanceShippingRate->getPrice();
            $priceDirect = $directNoLiftRate->getPrice();

            if ($priceDistance <= $priceDirect) {
                unset($rates[$directNoLiftKey]);
            } else {
                unset($rates[$distanceShippingKey]);
            }
        }

        return array_values($rates);
    }
}
