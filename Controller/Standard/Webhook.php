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
        if (is_array($event) && isset($event['type']) && $event['type'] === 'payment.succeeded') {
            $reference = isset($event['data']['payment']['reference']) ? $event['data']['payment']['reference'] : '';
            $this->markOrderPaid($reference);
        }

        return $this->json(200, ['received' => true]);
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
