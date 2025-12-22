# GardenLawn Delivery Module Documentation

This document provides an overview of the GardenLawn Delivery module, focusing on the distance calculation and shipping carrier implementations.

## Directory Structure

- **Helper**: Contains configuration helpers.
- **Model/Carrier**: Contains shipping carrier implementations.
- **Service**: Contains service classes for distance calculation.
- **ViewModel**: Contains view models for frontend interaction.

## Files Overview

### 1. Helper/Config.php

This helper class provides methods to retrieve module configuration values from the Magento system configuration.

**Key Constants:**
- `XML_PATH_ENABLED`: 'delivery/general/enabled'
- `XML_PATH_WAREHOUSE_ORIGIN`: 'delivery/general/warehouse_origin'
- `XML_PATH_PROVIDER`: 'delivery/api_provider/provider'
- `XML_PATH_GOOGLE_API_KEY`: 'delivery/api_provider/google_maps_api_key'
- `XML_PATH_HERE_API_KEY`: 'delivery/api_provider/here_api_key'
- Truck settings constants (height, width, length, weight, etc.)

**Key Methods:**
- `isEnabled($storeId = null)`: Checks if the module is enabled.
- `getWarehouseOrigin($storeId = null)`: Retrieves the warehouse origin address.
- `getProvider($storeId = null)`: Retrieves the configured API provider (Google or HERE).
- `getGoogleApiKey($storeId = null)`: Retrieves the Google Maps API key.
- `getHereApiKey($storeId = null)`: Retrieves the HERE API key.
- `getTruckParameters($storeId = null)`: Retrieves an array of truck parameters for HERE API.

### 2. Model/Carrier/DistanceShipping.php

This class implements the "Distance Shipping" carrier method. It calculates shipping costs based on the distance between the warehouse and the customer's address, potentially including intermediate points.

**Key Features:**
- Uses `DistanceService` to calculate distances.
- Supports multi-point routes via `getDistanceForConfigWithPoints`.
- Calculates price based on a configured prices table (`prices_table`).
- Supports a target SKU (`target_sku`) for specific product shipping logic.
- Calculates price based on quantity (m2), palette count, and base kilometers.

**Key Methods:**
- `collectRates(RateRequest $request)`: The main method for calculating shipping rates.
- `getDistanceForConfig($address)`: Calculates distance from origin to address.
- `getDistanceForConfigWithPoints($address)`: Calculates distance including intermediate points defined in config.

### 3. Model/Carrier/CourierWithElevatorShipping.php

This class implements the "Courier with Elevator Shipping" carrier method. It calculates shipping costs based on distance and load factors.

**Key Features:**
- Uses `DistanceService` to calculate distance from warehouse to customer.
- Calculates price based on price per km (`price`), load factors (`factor_min`, `factor_max`), and maximum load (`max_load`).
- Targets specific SKUs (`target_sku`).

**Key Methods:**
- `collectRates(RateRequest $request)`: Calculates the shipping rate based on distance and load logic.

### 4. Service/DistanceService.php

This service class is responsible for calculating distances using external APIs (Google Maps or HERE).

**Key Features:**
- Supports switching between Google Maps and HERE Technologies via configuration.
- Implements `getDistance($origin, $destination)` as the main entry point.
- `getDistanceFromGoogle`: Uses Google Distance Matrix API.
- `getDistanceFromHere`: Uses HERE Routing API v8.
- `geocodeAddress`: Geocodes addresses using HERE Geocoding API (required for HERE Routing).
- `buildTruckParameters`: Constructs truck-specific parameters for HERE API requests.
- `getDistanceForPoints`: Calculates total distance for a sequence of points.

### 5. ViewModel/DistanceCalculator.php

This ViewModel is likely used in frontend templates to display distance information or calculate delivery times.

**Key Features:**
- Uses `Config` helper and `Curl` client.
- `getDistance($origin, $destination)`: Returns distance and duration data, supporting both Google and HERE providers.
- `getHereDistance`: Specific implementation for HERE API, including truck parameters and detailed response parsing (distance text, duration text, arrival time).
- `getGoogleDistance`: Specific implementation for Google API.
- `getHereCoordinates`: Helper to geocode addresses for HERE API.

## Configuration

The module is configured via `Stores > Configuration > GardenLawn > Delivery Settings`.

**Key Configuration Sections:**
- **General Configuration**: Enable/disable module, set warehouse origin.
- **API Provider**: Select provider (Google/HERE) and set API keys.
- **Truck Parameters (HERE API)**: Configure vehicle dimensions, weight, type, hazardous goods, and features to avoid.
- **Delivery Methods**: Configure specific settings for `DistanceShipping` and `CourierWithElevatorShipping` (enabled, title, prices table, etc.).
