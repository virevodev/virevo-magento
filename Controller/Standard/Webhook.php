<?php
/**
 * Webhook Virevo (payment.succeeded) — signature vérifiée → facture la commande.
 * Endpoint public (sécurité = HMAC), CSRF désactivé.
 *
 * @license GPLv2 or later
 */

namespace Virevo\Payment\Controller\Standard;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Virevo\Payment\Model\Config;

class Webhook implements ActionInterface, CsrfAwareActionInterface, HttpPostActionInterface
{
    const TOLERANCE_SECONDS = 300;

    /** @var HttpRequest */
    private $request;
    /** @var JsonFactory */
    private $resultJsonFactory;
    /** @var OrderFactory */
    private $orderFactory;
    /** @var Config */
    private $config;
    /** @var InvoiceService */
    private $invoiceService;
    /** @var Transaction */
    private $transaction;

    public function __construct(
        HttpRequest $request,
        JsonFactory $resultJsonFactory,
        OrderFactory $orderFactory,
        Config $config,
        InvoiceService $invoiceService,
        Transaction $transaction
    ) {
        $this->request = $request;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderFactory = $orderFactory;
        $this->config = $config;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true; // sécurité assurée par la signature HMAC.
    }

    public function execute()
    {
        $payload = $this->request->getContent();
        $signature = $this->request->getHeader('Virevo-Signature');

        if (!$this->verifySignature($this->config->getWebhookSecret(), $signature, $payload)) {
            return $this->json(400, ['error' => 'invalid signature']);
        }

        $event = json_decode($payload, true);
        $type = is_array($event) && isset($event['type']) ? $event['type'] : '';
        $reference = isset($event['data']['payment']['reference'])
            ? $event['data']['payment']['reference']
            : '';

        switch ($type) {
            case 'payment.succeeded':
                $this->markOrderPaid($reference);
                break;

            // Les TROIS façons dont un paiement se termine sans argent. Jusqu'au
            // 2026-09-24 elles étaient acquittées puis jetées, et la commande
            // restait en « pending_payment » indéfiniment, son stock réservé.
            case 'payment.failed':
            case 'payment.canceled':
            case 'payment.expired':
                $this->closeUnpaidOrder($reference, $type);
                break;

            case 'payment.refunded':
                $this->noteRefund($event, $reference);
                break;
        }

        // Un type inconnu est acquitté volontairement : un événement ajouté plus
        // tard ne doit pas faire échouer la livraison chez les marchands qui
        // n'ont pas mis à jour le module.
        return $this->json(200, ['received' => true]);
    }

    /**
     * Annule une commande dont le paiement ne viendra pas.
     *
     * Deux gardes valent plus que le reste de la méthode.
     *
     * 1. **Une commande facturée n'est jamais touchée.** Un `payment.failed` peut
     *    arriver après un `payment.succeeded` : notification tardive, ou
     *    tentative précédente annoncée en retard. Annuler alors une commande
     *    réglée serait bien pire que de ne rien faire.
     * 2. **`canCancel()` fait foi.** Magento refuse d'annuler ce qui ne peut plus
     *    l'être, et c'est lui qui restitue le stock réservé.
     */
    private function closeUnpaidOrder($incrementId, $type)
    {
        if (!$incrementId) {
            return;
        }
        $order = $this->orderFactory->create()->loadByIncrementId($incrementId);
        if (!$order->getId()) {
            return;
        }
        if ($order->hasInvoices()) {
            return;
        }
        if (!$order->canCancel()) {
            return; // déjà annulée, ou état qui l'interdit.
        }

        if ($type === 'payment.expired') {
            $reason = __('Demande de paiement Virevo expirée : elle n\'est plus payable.');
        } elseif ($type === 'payment.failed') {
            $reason = __('Virement refusé (Virevo) : plafond dépassé, ou refus de la banque du client.');
        } else {
            $reason = __('Paiement Virevo annulé avant règlement.');
        }

        $order->cancel();
        $order->addCommentToStatusHistory($reason);
        $order->save();
    }

    /**
     * Trace un remboursement décidé depuis le tableau de bord Virevo.
     *
     * **Aucun avoir n'est créé, volontairement.** Un avoir Magento demande
     * `CreditmemoFactory` et `CreditmemoService`, et ce module n'a encore jamais
     * tourné sur une instance réelle : mieux vaut un commentaire exact qu'une
     * écriture comptable jamais éprouvée. Le marchand voit le remboursement et
     * garde la main sur l'avoir.
     *
     * Le sens inverse, lui, fonctionne déjà : « Refund Online » depuis
     * l'administration appelle `Virevo\Payment\Model\Virevo::refund()`, et
     * Magento crée l'avoir lui-même.
     */
    private function noteRefund(array $event, $incrementId)
    {
        if (!$incrementId) {
            return;
        }
        $order = $this->orderFactory->create()->loadByIncrementId($incrementId);
        if (!$order->getId()) {
            return;
        }

        $cents = isset($event['data']['refund']['amount_cents'])
            ? (int) $event['data']['refund']['amount_cents']
            : 0;
        if ($cents <= 0) {
            return;
        }

        $order->addCommentToStatusHistory(
            __(
                'Remboursement de %1 enregistré chez Virevo. Aucun avoir n\'a été créé automatiquement.',
                $order->getBaseCurrency()->formatTxt($cents / 100)
            )
        );
        $order->save();
    }

    private function markOrderPaid($incrementId)
    {
        if (!$incrementId) {
            return;
        }
        $order = $this->orderFactory->create()->loadByIncrementId($incrementId);
        if (!$order->getId()) {
            return;
        }
        if ($order->hasInvoices() || !$order->canInvoice()) {
            return; // idempotent.
        }

        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->register();
        $this->transaction->addObject($invoice)->addObject($order)->save();

        $order->setState(Order::STATE_PROCESSING)->setStatus(Order::STATE_PROCESSING);
        $order->addCommentToStatusHistory(__('Virement instantané reçu (Virevo).'));
        $order->save();
    }

    /**
     * Plusieurs v1 possibles pendant une rotation de secret (ancien + nouveau) :
     * on accepte si l'un d'eux correspond.
     */
    private function verifySignature($secret, $header, $body)
    {
        if (empty($secret) || empty($header)) {
            return false;
        }
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) !== 2) {
                continue;
            }
            $k = trim($kv[0]);
            $v = trim($kv[1]);
            if ($k === 't') {
                $t = (int) $v;
            } elseif ($k === 'v1' && $v !== '') {
                $sigs[] = $v;
            }
        }
        if ($t === null || empty($sigs)) {
            return false;
        }
        if (abs(time() - $t) > self::TOLERANCE_SECONDS) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $body, $secret);
        foreach ($sigs as $v1) {
            if (hash_equals($expected, $v1)) {
                return true;
            }
        }
        return false;
    }

    private function json($code, array $data)
    {
        $result = $this->resultJsonFactory->create();
        $result->setHttpResponseCode($code);
        $result->setData($data);
        return $result;
    }
}
