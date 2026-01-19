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
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\Config as TaxConfig;

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
    protected $distanceService;
    protected $taxCalculation;
    protected $taxConfig;

    protected $debugInfo = [];
    protected $lastPriceDetails = [];
    protected static $lastRequestTime = 0.0;

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
        Json $json,
        DistanceService $distanceService,
        Calculation $taxCalculation,
        TaxConfig $taxConfig
    ) {
        $this->apiService = $apiService;
        $this->requestFactory = $requestFactory;
        $this->scopeConfig = $scopeConfig;
        $this->currencyFactory = $currencyFactory;
        $this->storeManager = $storeManager;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
        $this->json = $json;
        $this->distanceService = $distanceService;
        $this->taxCalculation = $taxCalculation;
        $this->taxConfig = $taxConfig;
    }

    public function getDebugInfo()
    {
        return $this->debugInfo;
    }

    public function getLastPriceDetails()
    {
        return $this->lastPriceDetails;
    }

    /**
     * Resolve parameters based on rules and configuration without sending request
     */
    public function prepareRequestParams(string $carrierCode, float $qty)
    {
        return $this->resolveParams($carrierCode, $qty);
    }

    protected function resolveParams(string $carrierCode, float $qty)
    {
        $result = [
            'success' => false,
            'params' => [],
            'debug' => []
        ];

        $debug = [];
        $configPath = "carriers/$carrierCode/";

        // 1. Determine Vehicle from Rules
        $vehicleRulesJson = $this->scopeConfig->getValue('delivery/trans_eu_rules/vehicle_rules');
        $vehicleSizes = [];
        $requiredTruckBodies = [];
        $palletsCount = 0;
        $rulePalletLength = null;
        $rulePalletWidth = null;
        $ruleFreightType = null;
        $matchedRule = null;

        if ($vehicleRulesJson) {
            try {
                $rules = $this->json->unserialize($vehicleRulesJson);
                usort($rules, function($a, $b) {
                    return $a['max_pallets'] <=> $b['max_pallets'];
                });

                foreach ($rules as $rule) {
                    $m2PerPallet = isset($rule['m2_per_pallet']) && $rule['m2_per_pallet'] > 0 ? (float)$rule['m2_per_pallet'] : 35.0;
                    $calculatedPallets = ceil($qty / $m2PerPallet);

                    if ($calculatedPallets <= $rule['max_pallets']) {
                        $matchedRule = $rule;
                        $debug[] = "Matched rule: Max Pallets {$rule['max_pallets']} (Calculated: $calculatedPallets)";

                        $rawSize = $rule['vehicle_size'];
                        if (is_array($rawSize)) {
                            $vehicleSizes = $rawSize;
                        } elseif (is_string($rawSize)) {
                            $vehicleSizes = explode(',', $rawSize);
                        }

                        $rawBody = $rule['vehicle_bodies'];
                        if (is_array($rawBody)) {
                            $requiredTruckBodies = $rawBody;
                        } elseif (is_string($rawBody)) {
                            $requiredTruckBodies = explode(',', $rawBody);
                        }

                        if (isset($rule['freight_type']) && $rule['freight_type']) {
                            $ruleFreightType = $rule['freight_type'];
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
                $debug[] = "Error processing rules: " . $e->getMessage();
            }
        }

        // Fallback
        if (empty($vehicleSizes)) {
            $debug[] = "No rule matched. Using fallback configuration.";
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
            $debug[] = "Missing vehicle configuration.";
            $result['debug'] = $debug;
            return $result;
        }

        $finalVehicleSizeId = $this->resolveVehicleSizeId($vehicleSizes);
        if (!$finalVehicleSizeId) {
            $debug[] = "Could not resolve vehicle size ID.";
            $result['debug'] = $debug;
            return $result;
        }

        // Calculate weight
        $weightPerM2 = 25.0;
        $targetSku = $this->scopeConfig->getValue($configPath . 'target_sku') ?: 'GARDENLAWNS001';
        try {
            $product = $this->productRepository->get($targetSku);
            if ($product->getWeight() > 0) {
                $weightPerM2 = (float)$product->getWeight();
            }
        } catch (\Exception $e) {}

        $totalWeightKg = $qty * $weightPerM2;
        $capacityTons = ceil($totalWeightKg / 1000);
        if ($capacityTons < 1) $capacityTons = 1;

        $freightType = $ruleFreightType ?: ($this->scopeConfig->getValue($configPath . 'transeu_freight_type') ?: 'ftl');
        $loadType = $this->scopeConfig->getValue($configPath . 'transeu_load_type') ?: '2_europalette';

        // Calculate LDM
        $dims = $this->getLoadDimensions($loadType);
        $loadLength = $rulePalletLength ?: $dims['length'];
        $loadWidth = $rulePalletWidth ?: $dims['width'];

        $totalLdm = ($palletsCount * $loadLength * $loadWidth) / 2.4;
        $totalLdm = max(0.1, round($totalLdm, 1));

        $otherRequirements = [];
        $otherReqConfig = $this->scopeConfig->getValue($configPath . 'transeu_other_requirements');
        if ($otherReqConfig) {
            $otherRequirements = explode(',', $otherReqConfig);
        }

        $result['success'] = true;
        $result['debug'] = $debug;
        $result['matched_rule'] = $matchedRule;
        $result['params'] = [
            'vehicle_size_id' => $finalVehicleSizeId,
            'vehicle_bodies' => $requiredTruckBodies,
            'freight_type' => $freightType,
            'capacity_tons' => $capacityTons,
            'load_amount' => $palletsCount,
            'load_type' => $loadType,
            'load_length' => $loadLength,
            'load_width' => $loadWidth,
            'total_ldm' => $totalLdm,
            'other_requirements' => $otherRequirements
        ];

        return $result;
    }

    public function getPrice(string $carrierCode, string $originAddress, string $destinationAddress, float $distanceKm, float $qty)
    {
        $this->debugInfo = [
            'carrier' => $carrierCode,
            'qty_m2' => $qty,
            'distance_km' => $distanceKm,
            'steps' => [],
            'matched_rule' => null,
            'vehicle_params' => [],
            'load_params' => [],
            'request_payload' => null,
            'api_response' => null,
            'price_calculation' => []
        ];
        $this->lastPriceDetails = [];

        try {
            $configPath = "carriers/$carrierCode/";
            if (!$this->scopeConfig->isSetFlag($configPath . 'use_transeu_api')) {
                $this->debugInfo['steps'][] = "Trans.eu API disabled for this carrier.";
                return null;
            }

            $companyId = 1242549; // TODO: Get from config
            $userId = 1903733; // TODO: Get from config
            $priceFactor = (float)$this->scopeConfig->getValue($configPath . 'transeu_price_factor');
            if ($priceFactor <= 0) $priceFactor = 1;

            // Resolve Params
            $resolved = $this->resolveParams($carrierCode, $qty);
            $this->debugInfo['steps'] = array_merge($this->debugInfo['steps'], $resolved['debug']);
            $this->debugInfo['matched_rule'] = $resolved['matched_rule'] ?? null;

            if (!$resolved['success']) {
                return null;
            }

            $params = $resolved['params'];

            $this->debugInfo['vehicle_params'] = [
                'size_id' => $params['vehicle_size_id'],
                'bodies' => $params['vehicle_bodies'],
                'freight_type' => $params['freight_type'],
                'capacity_tons' => $params['capacity_tons'],
                'other_req' => $params['other_requirements']
            ];

            $this->debugInfo['load_params'] = [
                'pallets_count' => $params['load_amount'],
                'load_type' => $params['load_type'],
                'pallet_dims' => "{$params['load_length']} x {$params['load_width']}",
                'total_ldm' => $params['total_ldm']
            ];

            // Get coordinates and address details
            $originInfo = $this->distanceService->getCoordinates($originAddress);
            $destInfo = $this->distanceService->getCoordinates($destinationAddress);

            // Round coordinates to 6 decimal places
            if ($originInfo) {
                $originInfo['lat'] = round($originInfo['lat'], 6);
                $originInfo['lng'] = round($originInfo['lng'], 6);
            }
            if ($destInfo) {
                $destInfo['lat'] = round($destInfo['lat'], 6);
                $destInfo['lng'] = round($destInfo['lng'], 6);
            }

            $this->debugInfo['coordinates'] = [
                'origin' => $originInfo,
                'dest' => $destInfo
            ];

            // Build Request
            /** @var \GardenLawn\TransEu\Model\Data\PricePredictionRequest $requestModel */
            $requestModel = $this->requestFactory->create();

            $requestModel->setCompanyId($companyId);
            $requestModel->setUserId($userId);
            $requestModel->setDistance($distanceKm * 1000);
            $requestModel->setCurrency('EUR');

            $originParts = $this->parseAddress($originAddress);
            $destParts = $this->parseAddress($destinationAddress);

            // Use geocoded city/zip if available, otherwise fallback to parsed
            $originCity = !empty($originInfo['city']) ? $originInfo['city'] : $originParts['city'];
            $originZip = !empty($originInfo['zip']) ? $originInfo['zip'] : $originParts['zip'];

            $destCity = !empty($destInfo['city']) ? $destInfo['city'] : $destParts['city'];
            $destZip = !empty($destInfo['zip']) ? $destInfo['zip'] : $destParts['zip'];

            $pickupDate = date('Y-m-d', strtotime('+1 day'));
            $deliveryDate = date('Y-m-d', strtotime('+2 days'));

            $formatDate = function($dateStr) {
                return gmdate('Y-m-d\TH:i:s.000\Z', strtotime($dateStr));
            };

            $defaultLoad = [
                "amount" => $params['load_amount'],
                "name" => "Trawa w rolce ($qty m2)",
                "type_of_load" => $params['load_type'],
            ];

            $spots = [
                [
                    "operations" => [["loads" => [$defaultLoad]]],
                    "place" => [
                        "address" => ["locality" => $originCity, "postal_code" => $originZip],
                        "coordinates" => [
                            "latitude" => $originInfo['lat'] ?? 0,
                            "longitude" => $originInfo['lng'] ?? 0
                        ],
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
                        "address" => ["locality" => $destCity, "postal_code" => $destZip],
                        "coordinates" => [
                            "latitude" => $destInfo['lat'] ?? 0,
                            "longitude" => $destInfo['lng'] ?? 0
                        ],
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
                "capacity" => $params['capacity_tons'],
                "gps" => true,
                "other_requirements" => $params['other_requirements'],
                "required_truck_bodies" => $params['vehicle_bodies'],
                "required_ways_of_loading" => [],
                "vehicle_size_id" => $params['vehicle_size_id'],
                "transport_type" => $params['freight_type']
            ];
            $requestModel->setVehicleRequirements($vehicleRequirements);
            $requestModel->setData('length', $params['total_ldm']);

            $this->debugInfo['request_payload'] = $requestModel->toArray();

            // Log Request
            $this->logger->info("Trans.eu Request ($carrierCode): " . json_encode($this->debugInfo['request_payload']));

            // Rate limiting logic
            $currentTime = microtime(true);
            $timeSinceLast = $currentTime - self::$lastRequestTime;
            if ($timeSinceLast < 1.0) {
                $sleepTime = (1.0 - $timeSinceLast) * 1000000; // microseconds
                usleep((int)$sleepTime);
            }
            self::$lastRequestTime = microtime(true);

            // Call API
            $response = $this->apiService->predictPrice($requestModel);
            $this->debugInfo['api_response'] = $response;

            // Log Response
            $this->logger->info("Trans.eu Response ($carrierCode): " . json_encode($response));

            if (isset($response['prediction'][0]) && isset($response['currency']) && $response['currency'] == 'EUR') {
                $priceEur = $response['prediction'][0];
                $basePricePln = $this->convertEurToStoreCurrency($priceEur * $priceFactor);

                // Calculate Tax and Rounding
                $taxClassId = $this->scopeConfig->getValue('tax/classes/shipping_tax_class');
                $taxRate = 0;

                if ($taxClassId) {
                    $request = $this->taxCalculation->getRateRequest(null, null, null, $this->storeManager->getStore());
                    $request->setProductClassId($taxClassId);
                    $taxRate = $this->taxCalculation->getRate($request);
                }

                $grossPrice = $basePricePln * (1 + $taxRate / 100);
                $grossPriceRounded = ceil($grossPrice);
                $finalNetPrice = $grossPriceRounded / (1 + $taxRate / 100);

                $this->debugInfo['price_calculation'] = [
                    'base_eur' => $priceEur,
                    'factor' => $priceFactor,
                    'factored_eur' => $priceEur * $priceFactor,
                    'base_pln' => $basePricePln,
                    'tax_rate' => $taxRate,
                    'gross_calculated' => $grossPrice,
                    'gross_rounded' => $grossPriceRounded,
                    'final_net_price' => $finalNetPrice
                ];

                $this->lastPriceDetails = [
                    'net' => $finalNetPrice,
                    'gross' => $grossPriceRounded,
                    'tax_rate' => $taxRate
                ];

                return (float)$finalNetPrice;
            } else {
                $this->debugInfo['steps'][] = "Invalid API response or missing prediction.";
            }

        } catch (\Exception $e) {
            $this->logger->error("Trans.eu Quote Error ($carrierCode): " . $e->getMessage());
            $this->debugInfo['steps'][] = "Exception: " . $e->getMessage();
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
