<?php
namespace GardenLawn\Delivery\Service;

use GardenLawn\TransEu\Model\ApiService;
use GardenLawn\TransEu\Model\Data\PricePredictionRequestFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Serialize\Serializer\Json;

class TransEuQuoteService
{
    protected $apiService;
    protected $requestFactory;
    protected $scopeConfig;
    protected $currencyFactory;
    protected $storeManager;
    protected $productRepository;
    protected $logger;
    protected $json;

    protected $loadDimensions = [
        '2_europalette' => ['length' => 1.2, 'width' => 0.8],
        '34_eur_6' => ['length' => 0.8, 'width' => 0.6],
        '35_eur_2' => ['length' => 1.2, 'width' => 1.0],
        '36_eur_3' => ['length' => 1.0, 'width' => 1.2],
        '37_container_palette' => ['length' => 1.14, 'width' => 1.14],
        '38_oversized' => ['length' => 1.2, 'width' => 1.2],
        '3_big_bag' => ['length' => 0.9, 'width' => 0.9],
        '5_bag' => ['length' => 0.9, 'width' => 0.9],
    ];

    public function __construct(
        ApiService $apiService,
        PricePredictionRequestFactory $requestFactory,
        ScopeConfigInterface $scopeConfig,
        CurrencyFactory $currencyFactory,
        StoreManagerInterface $storeManager,
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger,
        Json $json
    ) {
        $this->apiService = $apiService;
        $this->requestFactory = $requestFactory;
        $this->scopeConfig = $scopeConfig;
        $this->currencyFactory = $currencyFactory;
        $this->storeManager = $storeManager;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
        $this->json = $json;
    }

    /**
     * Get predicted price for delivery
     *
     * @param string $carrierCode
     * @param string $originAddress
     * @param string $destinationAddress
     * @param float $distanceKm
     * @param float $qty Quantity in m2
     * @return float|null Price in store currency or null if failed
     */
    public function getPrice(string $carrierCode, string $originAddress, string $destinationAddress, float $distanceKm, float $qty)
    {
        try {
            // 1. Check if enabled for this carrier
            $configPath = "carriers/$carrierCode/";
            if (!$this->scopeConfig->isSetFlag($configPath . 'use_transeu_api')) {
                return null;
            }

            // 2. Get configuration
            $companyId = 1242549; // TODO: Get from config
            $userId = 1903733; // TODO: Get from config
            $priceFactor = (float)$this->scopeConfig->getValue($configPath . 'transeu_price_factor');
            if ($priceFactor <= 0) $priceFactor = 1;

            // 3. Determine Vehicle from Rules
            $vehicleRulesJson = $this->scopeConfig->getValue('delivery/trans_eu_rules/vehicle_rules');
            $vehicleSizes = [];
            $requiredTruckBodies = [];
            $palletsCount = 0;
            $rulePalletLength = null;
            $rulePalletWidth = null;

            if ($vehicleRulesJson) {
                try {
                    $rules = $this->json->unserialize($vehicleRulesJson);
                    // Sort rules by max_pallets ascending to find the smallest fitting vehicle
                    usort($rules, function($a, $b) {
                        return $a['max_pallets'] <=> $b['max_pallets'];
                    });

                    foreach ($rules as $rule) {
                        $m2PerPallet = isset($rule['m2_per_pallet']) && $rule['m2_per_pallet'] > 0 ? (float)$rule['m2_per_pallet'] : 35.0;
                        $calculatedPallets = ceil($qty / $m2PerPallet);

                        if ($calculatedPallets <= $rule['max_pallets']) {
                            $rawSize = $rule['vehicle_size'];
                            if (is_array($rawSize)) {
                                $vehicleSizes = $rawSize;
                            } elseif (is_string($rawSize)) {
                                $vehicleSizes = explode(',', $rawSize);
                            }

                            $requiredTruckBodies = $rule['vehicle_bodies'];
                            if (!is_array($requiredTruckBodies)) {
                                $requiredTruckBodies = [$requiredTruckBodies];
                            }

                            if (isset($rule['pallet_length']) && $rule['pallet_length'] > 0) {
                                $rulePalletLength = (float)$rule['pallet_length'];
                            }
                            if (isset($rule['pallet_width']) && $rule['pallet_width'] > 0) {
                                $rulePalletWidth = (float)$rule['pallet_width'];
                            }

                            $palletsCount = $calculatedPallets;
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->error("Trans.eu Rules Error: " . $e->getMessage());
                }
            }

            // Fallback to carrier config if no rule matched
            if (empty($vehicleSizes)) {
                // Default fallback calculation
                $m2PerPallet = 35.0;
                $palletsCount = ceil($qty / $m2PerPallet);

                $rawSize = $this->scopeConfig->getValue($configPath . 'transeu_vehicle_size');
                if ($rawSize) {
                    $vehicleSizes = explode(',', $rawSize);
                }

                $vehicleBody = $this->scopeConfig->getValue($configPath . 'transeu_vehicle_body');
                if ($vehicleBody) {
                    $requiredTruckBodies = explode(',', $vehicleBody);
                }
            }

            if (empty($requiredTruckBodies) || empty($vehicleSizes)) {
                $this->logger->warning("Trans.eu: Missing vehicle configuration for $carrierCode (Qty: $qty m2)");
                return null;
            }

            // Resolve combined vehicle size ID
            $finalVehicleSizeId = $this->resolveVehicleSizeId($vehicleSizes);
            if (!$finalVehicleSizeId) {
                $this->logger->warning("Trans.eu: Could not resolve vehicle size ID for " . implode(',', $vehicleSizes));
                return null;
            }

            // Calculate weight
            $weightPerM2 = 25.0; // 25kg per m2
            $targetSku = $this->scopeConfig->getValue($configPath . 'target_sku') ?: 'GARDENLAWNS001';
            try {
                $product = $this->productRepository->get($targetSku);
                if ($product->getWeight() > 0) {
                    $weightPerM2 = (float)$product->getWeight();
                }
            } catch (\Exception $e) {
                $this->logger->warning("Trans.eu: Product not found for weight calculation: $targetSku");
            }

            $totalWeightKg = $qty * $weightPerM2;
            $capacityTons = ceil($totalWeightKg / 1000);
            if ($capacityTons < 1) $capacityTons = 1;

            $freightType = $this->scopeConfig->getValue($configPath . 'transeu_freight_type') ?: 'ftl';
            $loadType = $this->scopeConfig->getValue($configPath . 'transeu_load_type') ?: '2_europalette';

            // Calculate LDM
            $dims = $this->getLoadDimensions($loadType);
            $loadLength = $rulePalletLength ?: $dims['length'];
            $loadWidth = $rulePalletWidth ?: $dims['width'];

            // Formula: (Quantity * Length * Width) / 2.4
            $totalLdm = ($palletsCount * $loadLength * $loadWidth) / 2.4;
            $totalLdm = max(0.1, round($totalLdm, 1));

            // Other requirements
            $otherRequirements = [];
            $otherReqConfig = $this->scopeConfig->getValue($configPath . 'transeu_other_requirements');
            if ($otherReqConfig) {
                $otherRequirements = explode(',', $otherReqConfig);
            }

            // 4. Build Request
            /** @var \GardenLawn\TransEu\Model\Data\PricePredictionRequest $requestModel */
            $requestModel = $this->requestFactory->create();

            $requestModel->setCompanyId($companyId);
            $requestModel->setUserId($userId);
            $requestModel->setDistance($distanceKm * 1000);
            $requestModel->setCurrency('EUR');

            $originParts = $this->parseAddress($originAddress);
            $destParts = $this->parseAddress($destinationAddress);

            $pickupDate = date('Y-m-d', strtotime('+1 day'));
            $deliveryDate = date('Y-m-d', strtotime('+2 days'));

            $formatDate = function($dateStr) {
                return gmdate('Y-m-d\TH:i:s.000\Z', strtotime($dateStr));
            };

            $defaultLoad = [
                "amount" => $palletsCount,
                "length" => $loadLength,
                "name" => "Trawa w rolce ($qty m2)",
                "type_of_load" => $loadType,
                "width" => $loadWidth,
            ];

            $spots = [
                [
                    "operations" => [["loads" => [$defaultLoad]]],
                    "place" => [
                        "address" => ["locality" => $originParts['city'], "postal_code" => $originParts['zip']],
                        "coordinates" => ["latitude" => 0, "longitude" => 0],
                        "country" => "PL"
                    ],
                    "timespans" => [
                        "begin" => $formatDate($pickupDate . ' 08:00:00'),
                        "end" => $formatDate($pickupDate . ' 16:00:00')
                    ],
                    "type" => "loading"
                ],
                [
                    "operations" => [["loads" => [$defaultLoad]]],
                    "place" => [
                        "address" => ["locality" => $destParts['city'], "postal_code" => $destParts['zip']],
                        "coordinates" => ["latitude" => 0, "longitude" => 0],
                        "country" => "PL"
                    ],
                    "timespans" => [
                        "begin" => $formatDate($deliveryDate . ' 08:00:00'),
                        "end" => $formatDate($deliveryDate . ' 16:00:00')
                    ],
                    "type" => "unloading"
                ]
            ];

            $requestModel->setSpots($spots);

            $vehicleRequirements = [
                "capacity" => $capacityTons,
                "gps" => true,
                "other_requirements" => $otherRequirements,
                "required_truck_bodies" => $requiredTruckBodies,
                "required_ways_of_loading" => [],
                "vehicle_size_id" => $finalVehicleSizeId,
                "transport_type" => $freightType
            ];
            $requestModel->setVehicleRequirements($vehicleRequirements);
            $requestModel->setData('length', $totalLdm);

            // 5. Call API
            $response = $this->apiService->predictPrice($requestModel);

            // 6. Convert Currency and Apply Factor
            if (isset($response['prediction'][0]) && isset($response['currency']) && $response['currency'] == 'EUR') {
                $priceEur = $response['prediction'][0];
                $priceEur *= $priceFactor;
                return $this->convertEurToStoreCurrency($priceEur);
            }

        } catch (\Exception $e) {
            $this->logger->error("Trans.eu Quote Error ($carrierCode): " . $e->getMessage());
        }

        return null;
    }

    protected function resolveVehicleSizeId(array $sizes)
    {
        $sizes = array_map('trim', $sizes);
        $sizes = array_filter(array_unique($sizes));

        $hasLorry = in_array('3_lorry', $sizes);
        $hasSolo = in_array('5_solo', $sizes);
        $hasDouble = in_array('2_double_trailer', $sizes);

        if ($hasLorry && $hasSolo && $hasDouble) {
            return '14_double_trailer_lorry_solo';
        }
        if ($hasLorry && $hasSolo) {
            return '8_lorry_solo';
        }
        if ($hasLorry && $hasDouble) {
            return '7_double_trailer_lorry';
        }
        if ($hasSolo && $hasDouble) {
            return '11_double_trailer_solo';
        }
        if ($hasLorry) return '3_lorry';
        if ($hasSolo) return '5_solo';
        if ($hasDouble) return '2_double_trailer';

        // Fallback: if only one size is selected and it's not one of the above
        if (count($sizes) === 1) {
            return reset($sizes);
        }

        return null;
    }

    protected function convertEurToStoreCurrency($priceEur)
    {
        try {
            $baseCurrencyCode = $this->storeManager->getStore()->getBaseCurrencyCode();

            if ($baseCurrencyCode == 'PLN') {
                $currencyPln = $this->currencyFactory->create()->load('PLN');
                $ratePlnToEur = $currencyPln->getRate('EUR');

                if ($ratePlnToEur && $ratePlnToEur > 0) {
                    return $priceEur * (1 / $ratePlnToEur);
                }
            }

            $currencyEur = $this->currencyFactory->create()->load('EUR');
            $rate = $currencyEur->getRate($baseCurrencyCode);
            if ($rate) {
                return $priceEur * $rate;
            }
        } catch (\Exception $e) {
            $this->logger->error("Currency Conversion Error: " . $e->getMessage());
        }

        return null;
    }

    protected function parseAddress($address)
    {
        $parts = array_map('trim', explode(',', $address));
        $count = count($parts);

        $city = $parts[$count - 2] ?? '';
        $zip = '';

        if (preg_match('/(\d{2}-\d{3})/', $address, $matches)) {
            $zip = $matches[1];
            $city = str_replace($zip, '', $city);
            $city = trim($city);
        } elseif (isset($parts[$count - 3])) {
             $zip = $parts[$count - 3];
        }

        return ['city' => $city, 'zip' => $zip];
    }

    protected function getLoadDimensions($loadType)
    {
        return $this->loadDimensions[$loadType] ?? $this->loadDimensions['default'];
    }
}
