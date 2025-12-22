<?php

namespace GardenLawn\Delivery\Controller\Adminhtml\Calculator;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\ResourceModel\Order\Grid\CollectionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;

class SearchOrders extends Action
{
    const ADMIN_RESOURCE = 'GardenLawn_Delivery::calculator';

    private JsonFactory $resultJsonFactory;
    private CollectionFactory $orderCollectionFactory;
    private OrderRepositoryInterface $orderRepository;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CollectionFactory $orderCollectionFactory,
        OrderRepositoryInterface $orderRepository
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->orderRepository = $orderRepository;
    }

    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        try {
            $query = $this->getRequest()->getParam('query');

            // Create order collection
            $collection = $this->orderCollectionFactory->create();

            // Apply search filter if query is provided
            if ($query) {
                $collection->addFieldToFilter(
                    ['increment_id', 'billing_name', 'customer_email'],
                    [
                        ['like' => '%' . $query . '%'],
                        ['like' => '%' . $query . '%'],
                        ['like' => '%' . $query . '%']
                    ]
                );
            }

            // Order by created_at descending
            $collection->setOrder('created_at', 'DESC');

            // Limit results
            $collection->setPageSize(50);

            $orders = [];

            foreach ($collection as $orderData) {
                $orderId = $orderData->getEntityId();
                $order = $this->orderRepository->get($orderId);
                $shippingAddress = $order->getShippingAddress();
                $addressString = '';

                if ($shippingAddress) {
                    $street = $shippingAddress->getStreet();
                    $addressString = (is_array($street) ? implode(', ', $street) : $street) . ', ' .
                                     $shippingAddress->getCity() . ', ' .
                                     $shippingAddress->getPostcode() . ', ' .
                                     $shippingAddress->getCountryId();
                }

                $orders[] = [
                    'id' => $orderId,
                    'increment_id' => $orderData->getIncrementId(),
                    'customer_name' => $orderData->getBillingName(),
                    'created_at' => $orderData->getCreatedAt(),
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
