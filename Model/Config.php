<?php
/**
 * Accès à la configuration de la passerelle Virevo.
 *
 * @license GPLv2 or later
 */

namespace Virevo\Payment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    const XML_PREFIX = 'payment/virevo/';

    /** @var ScopeConfigInterface */
    private $scopeConfig;
    /** @var EncryptorInterface */
    private $encryptor;

    public function __construct(ScopeConfigInterface $scopeConfig, EncryptorInterface $encryptor)
    {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    private function value($key, $storeId = null)
    {
        return $this->scopeConfig->getValue(self::XML_PREFIX . $key, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getMode($storeId = null)
    {
        return $this->value('mode', $storeId) ?: 'test';
    }

    /** Clé d'API du mode actif (déchiffrée). */
    public function getApiKey($storeId = null)
    {
        $field = $this->getMode($storeId) === 'live' ? 'live_api_key' : 'test_api_key';
        $value = $this->value($field, $storeId);
        return $value ? $this->encryptor->decrypt($value) : '';
    }

    public function getWebhookSecret($storeId = null)
    {
        $value = $this->value('webhook_secret', $storeId);
        return $value ? $this->encryptor->decrypt($value) : '';
    }
}
