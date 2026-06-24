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

    /** @var \Magento\Framework\UrlInterface */
    private $urlBuilder;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\UrlInterface $urlBuilder,
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
    }

    /** Magento redirige le client ici après le placement de la commande. */
    public function getOrderPlaceRedirectUrl()
    {
        return $this->urlBuilder->getUrl('virevo/standard/redirect');
    }
}
