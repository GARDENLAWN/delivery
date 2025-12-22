<?php

namespace GardenLawn\Delivery\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class SelectAction extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {
                $address = $item['shipping_address'] ?? '';
                $item[$this->getData('name')] = [
                    'select' => [
                        'label' => __('Use Address'),
                        'class' => 'use-address-btn',
                        'attributes' => [
                            'data-address' => $address
                        ]
                    ]
                ];
            }
        }
        return $dataSource;
    }
}
