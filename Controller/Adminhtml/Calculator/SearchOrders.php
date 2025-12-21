<?php

namespace GardenLawn\Delivery\Controller\Adminhtml\Calculator;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;

class SearchOrders extends Action
{
    const ADMIN_RESOURCE = 'GardenLawn_Delivery::calculator';

    private JsonFactory $resultJsonFactory;
    private CollectionFactory $orderCollectionFactory;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CollectionFactory $orderCollectionFactory
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderCollectionFactory = $orderCollectionFactory;
    }

    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();
        $query = $this->getRequest()->getParam('query');

        try {
            $collection = $this->orderCollectionFactory->create();
            $collection->addAttributeToSelect('*');

            if (!empty($query) && strlen($query) >= 3) {
                $collection->addAttributeToFilter(
                    [
                        ['attribute' => 'increment_id', 'like' => '%' . $query . '%'],
                        ['attribute' => 'customer_lastname', 'like' => '%' . $query . '%'],
                        ['attribute' => 'customer_email', 'like' => '%' . $query . '%']
                    ]
                );
            }

            $collection->setPageSize(10)
                ->setCurPage(1)
                ->setOrder('created_at', 'DESC');

            $orders = [];
            foreach ($collection as $order) {
                $shippingAddress = $order->getShippingAddress();

                // Skip orders without shipping address (e.g. virtual products)
                if (!$shippingAddress) {
                    continue;
                }

                $addressString = implode(', ', $shippingAddress->getStreet()) . ', ' .
                                 $shippingAddress->getCity() . ', ' .
                                 $shippingAddress->getPostcode() . ', ' .
                                 $shippingAddress->getCountryId();

                $orders[] = [
                    'id' => $order->getId(),
                    'increment_id' => $order->getIncrementId(),
                    'customer_name' => $order->getCustomerName(),
                    'created_at' => $order->getCreatedAt(),
                    'shipping_address' => $addressString
                ];
            }

            return $resultJson->setData([
                'success' => true,
                'orders' => $orders
            ]);

        } catch (\Exception $e) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('Error searching orders: %1', $e->getMessage())
            ]);
        }
    }
}
