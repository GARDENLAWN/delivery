<?php

namespace GardenLawn\Delivery\Controller\Calculator;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index implements HttpGetActionInterface
{
    private PageFactory $resultPageFactory;

    public function __construct(
        PageFactory $resultPageFactory
    ) {
        $this->resultPageFactory = $resultPageFactory;
    }

    public function execute()
    {
        return $this->resultPageFactory->create();
    }
}
