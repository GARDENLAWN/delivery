<?php
namespace GardenLawn\Delivery\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use GardenLawn\Delivery\Model\TransEu\AuthService;

class TransEuAuth extends Field
{
    protected $authService;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        AuthService $authService,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->authService = $authService;
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $url = $this->authService->getAuthorizationUrl();
        $label = __('Authorize with Trans.eu');

        $html = '<a href="' . $url . '" class="action-default" target="_blank">' . $label . '</a>';
        $html .= '<p class="note"><span>' . __('Click to authorize the application with Trans.eu. You will be redirected back after login.') . '</span></p>';

        return $html;
    }
}
