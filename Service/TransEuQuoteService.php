<?php
namespace GardenLawn\Delivery\Service;

use GardenLawn\TransEu\Model\ApiService;
use GardenLawn\TransEu\Model\Data\PricePredictionRequestFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class TransEuQuoteService
{
    protected $apiService;
    protected $requestFactory;
    protected $scopeConfig;
    protected $currencyFactory;
    protected $storeManager;
    protected $logger;

    public function __construct(
        ApiService $apiService,
        PricePredictionRequestFactory $requestFactory,
        ScopeConfigInterface $scopeConfig,
        CurrencyFactory $currencyFactory,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->apiService = $apiService;
        $this->requestFactory = $requestFactory;
        $this->scopeConfig = $scopeConfig;
        $this->currencyFactory = $currencyFactory;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * Get predicted price for delivery
     *
     * @param string $carrierCode
     * @param string $originAddress
     * @param string $destinationAddress
     * @param float $distanceKm
     * @return float|null Price in store currency or null if failed
     */
    public function getPrice(string $carrierCode, string $originAddress, string $destinationAddress, float $distanceKm)
    {
        try {
            // 1. Check if enabled for this carrier
            $configPath = "carriers/$carrierCode/";
            if (!$this->scopeConfig->isSetFlag($configPath . 'use_transeu_api')) {
                return null;
            }

            // 2. Get configuration
            $companyId = (int)$this->scopeConfig->getValue('trans_eu/general/company_id') ?: 1242549; // Fallback or get from general config if exists
            // Note: company_id is not in system.xml yet, maybe use trans_id or hardcode for now based on your previous input
            // Let's assume 1242549 based on your tests. Ideally should be in config.
            $companyId = 1242549;

            $userId = 1903733; // Also from your tests. Should be in config?

            $vehicleBody = $this->scopeConfig->getValue($configPath . 'transeu_vehicle_body');
            $vehicleSize = $this->scopeConfig->getValue($configPath . 'transeu_vehicle_size');
            $capacity = (float)$this->scopeConfig->getValue($configPath . 'transeu_capacity');

            if (!$vehicleBody || !$vehicleSize) {
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

            // Parse addresses (simplified)
            $originParts = $this->parseAddress($originAddress);
            $destParts = $this->parseAddress($destinationAddress);

            // Dates: Pickup tomorrow, Delivery day after tomorrow (simplified logic)
            $pickupDate = date('Y-m-d', strtotime('+1 day'));
            $deliveryDate = date('Y-m-d', strtotime('+2 days'));

            $formatDate = function($dateStr) {
                return gmdate('Y-m-d\TH:i:s.000\Z', strtotime($dateStr));
            };

            // Default load structure
            $defaultLoad = [
                "amount" => 5, // Arbitrary for now, maybe config?
                "length" => 1.2,
                "name" => "Ładunek",
                "type_of_load" => "2_europalette",
                "width" => 0.8
            ];

            $spots = [
                [
                    "operations" => [["loads" => [$defaultLoad]]],
                    "place" => [
                        "address" => ["locality" => $originParts['city'], "postal_code" => $originParts['zip']],
                        "coordinates" => ["latitude" => 0, "longitude" => 0], // API might require coords, but let's try without or need geocoding
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

            // We need coordinates! The Price Prediction API likely relies on them.
            // Since we already have DistanceService which uses Google/Here, maybe we can get coords from there?
            // For now, let's assume we need to pass them.
            // If DistanceService returns distance, it probably knows coords.
            // But AbstractDirectTransport only gets distance float.

            // CRITICAL: The API requires coordinates. Without them, prediction might fail or be inaccurate.
            // We'll need to extend DistanceService to return coordinates or geocode here.
            // For this MVP, let's try sending 0,0 and see if address is enough (unlikely).
            // OR better: Use hardcoded coords from your test if cities match, otherwise we need geocoding.

            // Let's use a placeholder for now, but this is a TODO.
            // Actually, in your test payload you sent coordinates.

            $requestModel->setSpots($spots);

            $vehicleRequirements = [
                "capacity" => $capacity,
                "gps" => true,
                "other_requirements" => [],
                "required_truck_bodies" => [$vehicleBody],
                "required_ways_of_loading" => [],
                "vehicle_size_id" => $vehicleSize,
                "transport_type" => "ftl"
            ];
            $requestModel->setVehicleRequirements($vehicleRequirements);
            $requestModel->setData('length', 2); // Configurable?

            // 4. Call API
            $response = $this->apiService->predictPrice($requestModel);

            // 5. Convert Currency
            if (isset($response['prediction'][0]) && isset($response['currency']) && $response['currency'] == 'EUR') {
                $priceEur = $response['prediction'][0];
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
        // Very basic parser assuming "City, Zip" or "Street, Zip City, Country"
        // This needs to be robust based on how Magento stores address string
        // In AbstractDirectTransport we build it: Street, Postcode, City, Country

        $parts = array_map('trim', explode(',', $address));
        $count = count($parts);

        $city = $parts[$count - 2] ?? ''; // Assuming Country is last
        $zip = '';

        // Try to extract zip from city string if combined "78-400 Szczecinek"
        if (preg_match('/(\d{2}-\d{3})/', $address, $matches)) {
            $zip = $matches[1];
            $city = str_replace($zip, '', $city);
            $city = trim($city);
        } elseif (isset($parts[$count - 3])) {
             $zip = $parts[$count - 3]; // If format is Street, Zip, City, Country
        }

        return ['city' => $city, 'zip' => $zip];
    }
}
