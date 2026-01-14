<?php
namespace GardenLawn\Delivery\Controller\Adminhtml\Auth;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use GardenLawn\Delivery\Model\TransEu\AuthService;

class Callback extends Action
{
    protected $authService;

    public function __construct(
        Context $context,
        AuthService $authService
    ) {
        parent::__construct($context);
        $this->authService = $authService;
    }

    public function execute()
    {
        $code = $this->getRequest()->getParam('code');
        $error = $this->getRequest()->getParam('error');

        if ($error) {
            $this->messageManager->addErrorMessage(__('Trans.eu authorization failed: %1', $error));
        } elseif ($code) {
            try {
                $this->authService->handleCallback($code);
                $this->messageManager->addSuccessMessage(__('Trans.eu authorization successful.'));
            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage(__('Error during token exchange: %1', $e->getMessage()));
            }
        } else {
            $this->messageManager->addErrorMessage(__('Invalid callback request.'));
        }

        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('adminhtml/system_config/edit', ['section' => 'delivery']);
        return $resultRedirect;
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('GardenLawn_Delivery::config');
    }
}
