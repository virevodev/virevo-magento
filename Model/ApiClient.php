<?php
/**
 * Client de l'API publique Virevo (/v1).
 *
 * @license GPLv2 or later
 */

namespace Virevo\Payment\Model;

use Magento\Framework\HTTP\Client\Curl;

class ApiClient
{
    const BASE_URL = 'https://app.virevo.fr';

    /** @var Curl */
    private $curl;

    public function __construct(Curl $curl)
    {
        $this->curl = $curl;
    }

    /**
     * Crée un paiement. Renvoie le tableau décodé ou null.
     */
    public function createPayment($apiKey, $amountCents, $currency, $reference, $returnUrl, $cancelUrl)
    {
        $body = [
            'amount_cents' => (int) $amountCents,
            'currency' => $currency,
            'reference' => (string) $reference,
        ];
        if ($returnUrl) {
            $body['return_url'] = $returnUrl;
        }
        if ($cancelUrl) {
            $body['cancel_url'] = $cancelUrl;
        }

        $this->curl->setOption(CURLOPT_TIMEOUT, 20);
        $this->curl->addHeader('Authorization', 'Bearer ' . $apiKey);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Idempotency-Key', (string) $reference);

        try {
            $this->curl->post(self::BASE_URL . '/v1/payments', json_encode($body));
        } catch (\Exception $e) {
            return null;
        }

        $status = (int) $this->curl->getStatus();
        if ($status < 200 || $status >= 300) {
            return null;
        }
        $data = json_decode($this->curl->getBody(), true);
        return is_array($data) ? $data : null;
    }
}
