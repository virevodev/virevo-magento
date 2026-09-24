<?php
/**
 * Méthode de paiement Virevo (offsite : redirection après placement de commande).
 *
 * NB : basée sur AbstractMethod (encore fonctionnel en 2.4) pour un flux offsite
 * simple. À migrer vers le Gateway/Adapter framework si besoin. getOrderPlace-
 * RedirectUrl renvoie vers le contrôleur qui crée le paiement et redirige.
 *
 * @license GPLv2 or later
 */

namespace Virevo\Payment\Model;

class Virevo extends \Magento\Payment\Model\Method\AbstractMethod
{
    protected $_code = 'virevo';
    protected $_isOffline = false;
    protected $_canUseCheckout = true;
    protected $_canUseInternal = false;
    protected $_isInitializeNeeded = false;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;

    /** @var \Magento\Framework\UrlInterface */
    private $urlBuilder;
    /** @var \Virevo\Payment\Model\Config */
    private $config;
    /** @var \Virevo\Payment\Model\ApiClient */
    private $api;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Virevo\Payment\Model\Config $config,
        \Virevo\Payment\Model\ApiClient $api,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->urlBuilder = $urlBuilder;
        $this->config = $config;
        $this->api = $api;
    }

    /** Magento redirige le client ici après le placement de la commande. */
    public function getOrderPlaceRedirectUrl()
    {
        return $this->urlBuilder->getUrl('virevo/standard/redirect');
    }

    /**
     * Ne propose la méthode que si elle peut réellement aboutir.
     *
     * Sans cette garde, une boutique qui active Virevo mais oublie la clé
     * affiche « Virement instantané » au checkout : le client le choisit, et se
     * prend une erreur d'API après avoir placé sa commande. Le paiement le plus
     * coûteux est celui qu'on laisse commencer alors qu'il ne peut pas finir.
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if (!parent::isAvailable($quote)) {
            return false;
        }

        // L'euro seul : le virement instantané est un instrument de la zone euro.
        if ($quote && $quote->getCurrency() && $quote->getCurrency()->getQuoteCurrencyCode() !== 'EUR') {
            return false;
        }

        return $this->config->isUsable($quote ? $quote->getStoreId() : null);
    }

    /**
     * Remboursement en ligne (avoir Magento « Refund Online »). $amount est dans
     * la devise de la commande (unités majeures). Pousse le remboursement à Virevo.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float                                 $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        $virevoId = $payment->getAdditionalInformation('virevo_payment_id');
        if (!$virevoId) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Identifiant de paiement Virevo introuvable sur cette commande.')
            );
        }

        $resp = $this->api->refund(
            $this->config->getApiKey($order->getStoreId()),
            (int) round((float) $amount * 100),
            $virevoId,
            ''
        );
        if (null === $resp) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Le remboursement Virevo a échoué (non disponible en production pour le moment ?).')
            );
        }

        return $this;
    }
}
