<?php

namespace Perfexcrm\Bachs;

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Confirmed directly against the real docs.bachs.io (2026-07-20, via a real
 * browser session -- the site 403s plain automated fetches) rather than
 * secondhand SDK-README summaries. Real, verified facts baked in here:
 *   - Sandbox and production are fully separate: different base URLs
 *     (sandbox-api.bachs.io / api.bachs.io), different API keys
 *     (sk_sandbox_.../sk_live_...), different webhook endpoints and
 *     signing secrets. Nothing is shared between them.
 *   - POST /v1/checkout-sessions real body: customer, product_cart[]
 *     (product_id, quantity, optional amount -- amount is a valid
 *     "custom amount override for CUSTOM-priced products" only), plus
 *     top-level success_url/cancel_url/billing_currency/
 *     allowed_payment_method_types/metadata/reference/expires_in_minutes.
 *     There is no "billing" (subscription vs one-time) parameter on this
 *     endpoint -- an earlier assumption based on a third-party SDK example
 *     was wrong. Subscriptions are driven by the product's own recurring
 *     price configuration, not a request-level flag.
 *   - The hosted checkout page and the embedded "overlay" checkout are the
 *     SAME backend checkout_url -- hosted redirects the browser to it
 *     server-side; overlay loads https://checkout.bachs.io/bachs.js
 *     client-side and opens the same URL in a modal
 *     (Bachs.Checkout.open({checkoutUrl})). This is a presentation choice,
 *     not a different API call.
 *   - POST /v1/refunds (not a per-charge sub-path): {charge_id, reference,
 *     amount?, reason?}. Omitting amount does a full refund.
 */
class BachsClient
{
    private string $baseUrl;
    private string $apiKey;

    /**
     * Settings are gateway-encrypted (paymentmethod_bachs_live_api_key etc,
     * see App_gateway::decryptSetting()) -- this client is always
     * constructed with already-decrypted values for whichever mode
     * (test/live) the gateway resolved, never reads options directly.
     */
    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey  = $apiKey;
    }

    public function createCheckoutSession(
        string $productId,
        string $amountMajor,
        string $email,
        string $name,
        string $reference,
        array $metadata,
        string $successUrl,
        string $cancelUrl
    ): array {
        return $this->request('POST', '/v1/checkout-sessions', [
            'product_cart' => [
                ['product_id' => $productId, 'quantity' => 1, 'amount' => $amountMajor],
            ],
            'customer'    => ['email' => $email, 'name' => $name],
            'reference'   => $reference,
            'metadata'    => $metadata,
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
        ]);
    }

    public function getCheckoutStatus(string $checkoutId): array
    {
        // Confirmed 2026-07-20 against a real live sandbox checkout: the
        // earlier /v1/checkouts/{id} path is wrong (404) -- the real
        // resource path mirrors the creation path, /v1/checkout-sessions/{id}.
        return $this->request('GET', "/v1/checkout-sessions/{$checkoutId}");
    }

    public function refund(string $chargeId, string $reference, ?string $amountMajor = null, string $reason = ''): array
    {
        $body = ['charge_id' => $chargeId, 'reference' => $reference];
        if ($amountMajor !== null) {
            $body['amount'] = $amountMajor;
        }
        if ($reason !== '') {
            $body['reason'] = $reason;
        }

        return $this->request('POST', '/v1/refunds', $body);
    }

    /**
     * Confirmed live 2026-07-20 against the real sandbox account: response
     * shape is {items: [...], pagination: {...}}, not {data: [...]} as
     * first guessed before a real key was available to test against.
     */
    public function listProducts(): array
    {
        $result = $this->request('GET', '/v1/products');

        return $result['items'] ?? [];
    }

    /**
     * Confirmed live 2026-07-20 (POST /v1/products, real 201 response) --
     * lets the module self-provision its own reusable custom-amount
     * product per currency instead of requiring a staff member to create
     * one by hand in the Bachs dashboard first.
     */
    public function createProduct(string $name, string $description, string $currency): array
    {
        return $this->request('POST', '/v1/products', [
            'name'        => $name,
            'description' => $description,
            'price'       => ['currency' => $currency, 'price_type' => 'custom'],
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
            $headers[] = 'Content-Type: application/json';
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
