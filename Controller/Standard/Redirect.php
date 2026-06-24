<?php
/**
 * Démarre le paiement : crée la demande Virevo et redirige vers payment_url.
 *
 * @license GPLv2 or later
 */

namespace Virevo\Payment\Controller\Standard;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Checkout\Model\Session as CheckoutSession;
use Virevo\Payment\Model\Config;
use Virevo\Payment\Model\ApiClient;

class Redirect extends Action
{
    /** @var CheckoutSession */
    private $checkoutSession;
    /** @var Config */
    private $config;
    /** @var ApiClient */
    private $api;

    public function __construct(Context $context, CheckoutSession $checkoutSession, Config $config, ApiClient $api)
    {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->config = $config;
        $this->api = $api;
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order || !$order->getId()) {
            return $resultRedirect->setPath('checkout/cart');
        }

        $resp = $this->api->createPayment(
            $this->config->getApiKey($order->getStoreId()),
            (int) round((float) $order->getGrandTotal() * 100),
            $order->getOrderCurrencyCode(),
            $order->getIncrementId(),
            $this->_url->getUrl('checkout/onepage/success'),
            $this->_url->getUrl('checkout/cart')
        );

        if (!$resp || empty($resp['payment_url'])) {
            $this->messageManager->addErrorMessage(__("Le paiement Virevo n'a pas pu être initié."));
            // Restaure le panier pour permettre une nouvelle tentative.
            $this->checkoutSession->restoreQuote();
            return $resultRedirect->setPath('checkout/cart');
        }

        // Mémorise l'identifiant Virevo sur le paiement (nécessaire au remboursement).
        $payment = $order->getPayment();
        if ($payment && !empty($resp['id'])) {
            $payment->setAdditionalInformation('virevo_payment_id', $resp['id']);
            $payment->setLastTransId($resp['id']);
            $order->save();
        }

        return $resultRedirect->setUrl($resp['payment_url']);
    }
}
