<?php
namespace GardenLawn\Delivery\Service;

use GardenLawn\TransEu\Model\ApiService;
use GardenLawn\TransEu\Model\Data\PricePredictionRequestFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Psr\Log\LoggerInterface;

class TransEuQuoteService
{
    protected $apiService;
    protected $requestFactory;
    protected $scopeConfig;
    protected $currencyFactory;
    protected $storeManager;
    protected $productRepository;
    protected $logger;

    public function __construct(
        ApiService $apiService,
        PricePredictionRequestFactory $requestFactory,
        ScopeConfigInterface $scopeConfig,
        CurrencyFactory $currencyFactory,
        StoreManagerInterface $storeManager,
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger
    ) {
        $this->apiService = $apiService;
        $this->requestFactory = $requestFactory;
        $this->scopeConfig = $scopeConfig;
        $this->currencyFactory = $currencyFactory;
        $this->storeManager = $storeManager;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
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

            $vehicleBody = $this->scopeConfig->getValue($configPath . 'transeu_vehicle_body');
            $vehicleSize = $this->scopeConfig->getValue($configPath . 'transeu_vehicle_size');
            $freightType = $this->scopeConfig->getValue($configPath . 'transeu_freight_type') ?: 'ftl';
            $loadType = $this->scopeConfig->getValue($configPath . 'transeu_load_type') ?: '2_europalette';
            $priceFactor = (float)$this->scopeConfig->getValue($configPath . 'transeu_price_factor');

            // Default factor to 1.2 if not set or invalid
            if ($priceFactor <= 0) {
                $priceFactor = 1.2;
            }

            // Handle multiselect for vehicle body
            $requiredTruckBodies = [];
            if ($vehicleBody) {
                $requiredTruckBodies = explode(',', $vehicleBody);
            }

            // Calculate capacity based on weight
            $weightPerUnit = 25.0; // Default 25kg per m2
            try {
                // Try to get weight from product GARDENLAWNS001
                $product = $this->productRepository->get('GARDENLAWNS001');
                if ($product->getWeight() > 0) {
                    $weightPerUnit = (float)$product->getWeight();
                }
            } catch (\Exception $e) {
                // Product not found or error, use default
            }

            $totalWeightKg = $qty * $weightPerUnit;
            $capacityTons = ceil($totalWeightKg / 1000); // Convert to tons and round up to integer

            // Ensure minimum capacity (e.g. 1 ton)
            if ($capacityTons < 1) {
                $capacityTons = 1;
            }

            if (empty($requiredTruckBodies) || !$vehicleSize) {
                $this->logger->warning("Trans.eu: Missing vehicle configuration for $carrierCode");
                return null;
            }

            // 3. Build Request
            /** @var \GardenLawn\TransEu\Model\Data\PricePredictionRequest $requestModel */
            $requestModel = $this->requestFactory->create();

            $requestModel->setCompanyId($companyId);
            $requestModel->setUserId($userId);
            $requestModel->setDistance($distanceKm * 1000); // Convert km to meters
            $requestModel->setCurrency('EUR');

            // Parse addresses
            $originParts = $this->parseAddress($originAddress);
            $destParts = $this->parseAddress($destinationAddress);

            // Dates
            $pickupDate = date('Y-m-d', strtotime('+1 day'));
            $deliveryDate = date('Y-m-d', strtotime('+2 days'));

            $formatDate = function($dateStr) {
                return gmdate('Y-m-d\TH:i:s.000\Z', strtotime($dateStr));
            };

            // Load structure
            // Assume 1 pallet = 50m2 for calculation of 'amount' (pallets count).
            $palletsCount = ceil($qty / 50);

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
                "other_requirements" => [],
                "required_truck_bodies" => $requiredTruckBodies,
                "required_ways_of_loading" => [],
                "vehicle_size_id" => $vehicleSize,
                "transport_type" => $freightType
            ];
            $requestModel->setVehicleRequirements($vehicleRequirements);
            $requestModel->setData('length', 2); // Configurable?

            // 4. Call API
            $response = $this->apiService->predictPrice($requestModel);

            // 5. Convert Currency and Apply Factor
            if (isset($response['prediction'][0]) && isset($response['currency']) && $response['currency'] == 'EUR') {
                $priceEur = $response['prediction'][0];

                // Apply Factor
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

            // Fallback
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
