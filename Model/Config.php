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

    /** Préfixe attendu de la clé, selon le mode actif. */
    public function getExpectedKeyPrefix($storeId = null)
    {
        return $this->getMode($storeId) === 'live' ? 'vrv_live_' : 'vrv_test_';
    }

    /**
     * La configuration permet-elle un paiement qui aboutit ?
     *
     * Une clé absente, ou une clé dont le préfixe contredit le mode, serait
     * rejetée par l'API. Le savoir sans appel réseau permet de masquer le
     * moyen de paiement au checkout plutôt que d'échouer après le clic.
     */
    public function isUsable($storeId = null)
    {
        $key = trim((string) $this->getApiKey($storeId));

        return $key !== '' && strpos($key, $this->getExpectedKeyPrefix($storeId)) === 0;
    }
}
