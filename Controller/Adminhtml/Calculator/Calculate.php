<?php

namespace GardenLawn\Delivery\Controller\Adminhtml\Calculator;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use GardenLawn\Delivery\ViewModel\DistanceCalculator;

class Calculate extends Action
{
    const ADMIN_RESOURCE = 'GardenLawn_Delivery::calculator';

    private JsonFactory $resultJsonFactory;
    private DistanceCalculator $distanceCalculator;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        DistanceCalculator $distanceCalculator
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->distanceCalculator = $distanceCalculator;
    }

    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        if (!$this->distanceCalculator->isEnabled()) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('Module is disabled.')
            ]);
        }

        $origin = $this->getRequest()->getParam('origin');
        $destination = $this->getRequest()->getParam('destination');

        if (!$origin || !$destination) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('Please provide both origin and destination addresses.')
            ]);
        }

        try {
            $result = $this->distanceCalculator->getDistance($origin, $destination);

            if ($result) {
                $element = $result['element'];
                $rawJson = $result['raw_json'];

                // Pretty print the JSON
                $prettyJson = json_encode(json_decode($rawJson), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                return $resultJson->setData([
                    'success' => true,
                    'distance' => $element['distance']['text'],
                    'duration' => $element['duration']['text'],
                    'departure_time' => $element['departure_time'],
                    'arrival_time' => $element['arrival_time'],
                    'raw_json' => $prettyJson
                ]);
            } else {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('Could not calculate distance. Please check the address or API key.')
                ]);
            }
        } catch (\Exception $e) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('An error occurred: %1', $e->getMessage())
            ]);
        }
    }
}
