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
            if ($priceFactor <= 0) $priceFactor = 1.2;

            // 3. Determine Vehicle from Rules
            $vehicleRulesJson = $this->scopeConfig->getValue('delivery/trans_eu_rules/vehicle_rules');
            $vehicleSize = null;
            $requiredTruckBodies = [];
            $palletsCount = 0;

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
                            $vehicleSize = $rule['vehicle_size'];
                            // Handle multiselect in rules
                            if (is_array($vehicleSize)) {
                                $vehicleSize = reset($vehicleSize);
                            } elseif (strpos($vehicleSize, ',') !== false) {
                                $parts = explode(',', $vehicleSize);
                                $vehicleSize = reset($parts);
                            }

                            $requiredTruckBodies = $rule['vehicle_bodies'];
                            if (!is_array($requiredTruckBodies)) {
                                $requiredTruckBodies = [$requiredTruckBodies];
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
            if (!$vehicleSize) {
                // Default fallback calculation
                $m2PerPallet = 35.0;
                $palletsCount = ceil($qty / $m2PerPallet);

                $vehicleSize = $this->scopeConfig->getValue($configPath . 'transeu_vehicle_size');
                if ($vehicleSize) {
                    $parts = explode(',', $vehicleSize);
                    $vehicleSize = reset($parts);
                }

                $vehicleBody = $this->scopeConfig->getValue($configPath . 'transeu_vehicle_body');
                if ($vehicleBody) {
                    $requiredTruckBodies = explode(',', $vehicleBody);
                }
            }

            if (empty($requiredTruckBodies) || !$vehicleSize) {
                $this->logger->warning("Trans.eu: Missing vehicle configuration for $carrierCode (Qty: $qty m2)");
                return null;
            }

            // Calculate weight
            $weightPerM2 = 25.0; // 25kg per m2
            try {
                $product = $this->productRepository->get('GARDENLAWNS001');
                if ($product->getWeight() > 0) {
                    $weightPerM2 = (float)$product->getWeight();
                }
            } catch (\Exception $e) {}

            $totalWeightKg = $qty * $weightPerM2;
            $capacityTons = ceil($totalWeightKg / 1000);
            if ($capacityTons < 1) $capacityTons = 1;

            $freightType = $this->scopeConfig->getValue($configPath . 'transeu_freight_type') ?: 'ftl';
            $loadType = $this->scopeConfig->getValue($configPath . 'transeu_load_type') ?: '2_europalette';

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
                "length" => 1.2,
                "name" => "Trawa w rolce ($qty m2)",
                "type_of_load" => $loadType,
                "width" => 0.8,
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
                "vehicle_size_id" => $vehicleSize,
                "transport_type" => $freightType
            ];
            $requestModel->setVehicleRequirements($vehicleRequirements);
            $requestModel->setData('length', 2);

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
}
