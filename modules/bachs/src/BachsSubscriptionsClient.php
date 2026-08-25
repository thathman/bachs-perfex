<?php

namespace Perfexcrm\Bachs;

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * The one Bachs API call the shared BachsClient cannot make for us.
 *
 * Confirmed empirically against the real sandbox (2026-08-23): a checkout
 * session for a FIXED-priced product -- which every recurring/subscription
 * product necessarily is -- rejects the per-line `amount` field outright:
 *
 *   "Product 'prod_...' has fixed pricing; amount must not be provided"
 *
 * BachsClient::createCheckoutSession() always sends `amount` (it exists for
 * the invoice flow's custom-amount products, where it is required), so it
 * can never be reused here. Rather than change that method's shape and risk
 * breaking the live invoice checkout path, this small sibling client issues
 * the fixed-price variant: product_cart without `amount`, quantity honoured.
 *
 * Verified live in sandbox: POST /v1/checkout-sessions against a product
 * carrying billing_cycle returns {"mode":"subscription","recurring":
 * {"interval":"month","interval_count":1,"amount":"5.00",...}} -- i.e. Bachs
 * itself decides this is a subscription purely from the product's own
 * recurring price configuration. quantity multiplies correctly (quantity 3
 * on a $5.00/mo product returned amount "15.00").
 */
class BachsSubscriptionsClient
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey  = $apiKey;
    }

    /**
     * Creates the checkout that Bachs turns into a subscription on successful
     * payment. There is no direct "create subscription" endpoint.
     */
    public function createSubscriptionCheckout(
        string $productId,
        int $quantity,
        string $email,
        string $name,
        string $reference,
        array $metadata,
        string $successUrl,
        string $cancelUrl
    ): array {
        return $this->request('POST', '/v1/checkout-sessions', [
            'product_cart' => [
                ['product_id' => $productId, 'quantity' => max(1, $quantity)],
            ],
            'customer'    => ['email' => $email, 'name' => $name],
            'reference'   => $reference,
            'metadata'    => $metadata,
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
        ]);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init($this->baseUrl . $path);

        $headers = ['Authorization: Bearer ' . $this->apiKey];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ];

        if ($body !== null) {
            $headers[]                   = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Bachs API request failed: ' . $error);
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $message = $decoded['detail'] ?? ('HTTP ' . $httpCode);
            throw new \RuntimeException('Bachs API error: ' . $message);
        }

        return $decoded ?? [];
    }
}
