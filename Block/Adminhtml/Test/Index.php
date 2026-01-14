<?php
namespace GardenLawn\Delivery\Block\Adminhtml\Test;

use Magento\Backend\Block\Template;
use GardenLawn\Delivery\Model\TransEu\AuthService;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Index extends Template
{
    protected $authService;
    protected $scopeConfig;

    public function __construct(
        Template\Context $context,
        AuthService $authService,
        ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->authService = $authService;
        $this->scopeConfig = $scopeConfig;
    }

    public function getConfigData()
    {
        return [
            'delivery/trans_eu/active' => 'Module Active',
            'delivery/trans_eu/client_id' => 'Client ID',
            'delivery/trans_eu/client_secret' => 'Client Secret',
            'delivery/trans_eu/api_key' => 'API Key',
            'delivery/trans_eu/trans_id' => 'Trans ID',
            'delivery/trans_eu/auth_url' => 'Auth URL',
            'delivery/trans_eu/api_url' => 'API URL',
            'delivery/trans_eu/redirect_uri' => 'Redirect URI',
        ];
    }

    public function getConfigValue($path)
    {
        return $this->scopeConfig->getValue($path);
    }

    public function getTokenInfo()
    {
        return [
            'access_token' => $this->scopeConfig->getValue('delivery/trans_eu/access_token'),
            'refresh_token' => $this->scopeConfig->getValue('delivery/trans_eu/refresh_token'),
            'expires_at' => $this->scopeConfig->getValue('delivery/trans_eu/token_expires')
        ];
    }

    public function testTokenRetrieval()
    {
        try {
            $start = microtime(true);
            $token = $this->authService->getAccessToken();
            $end = microtime(true);

            return [
                'success' => (bool)$token,
                'token' => $token,
                'duration' => round($end - $start, 4),
                'message' => $token ? 'Token retrieved successfully.' : 'Failed to retrieve token.'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }
}
