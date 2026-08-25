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
     *
     * $currencyOptions (added 2026-08-23, per docs.bachs.io/api-reference/
     * products/create-a-product): a product's price can carry
     * currency_options, e.g. [['currency' => 'NGN', 'amount' => '45000.00']]
     * -- one product genuinely priced in multiple currencies, rather than
     * three separately-maintained region products. Only meaningful on a
     * 'fixed' price_type; omit for the existing per-region 'custom' products.
     */
    public function createProduct(string $name, string $description, string $currency, array $currencyOptions = []): array
    {
        $price = ['currency' => $currency, 'price_type' => 'custom'];
        if ($currencyOptions) {
            $price['currency_options'] = $currencyOptions;
        }

        return $this->request('POST', '/v1/products', [
            'name'        => $name,
            'description' => $description,
            'price'       => $price,
        ]);
    }

    /**
     * A product with a real fixed amount (not 'custom') plus optional
     * currency_options and billing_cycle -- the shape a recurring/subscription
     * product needs. createProduct() above stays custom-price-only since
     * every existing call site relies on that; this is additive, not a
     * replacement.
     */
    public function createFixedProduct(
        string $name,
        string $description,
        string $currency,
        string $amount,
        array $currencyOptions = [],
        ?array $billingCycle = null,
        ?array $trialPeriod = null
    ): array {
        $price = ['currency' => $currency, 'price_type' => 'fixed', 'amount' => $amount];
        if ($currencyOptions) {
            $price['currency_options'] = $currencyOptions;
        }

        $body = ['name' => $name, 'description' => $description, 'price' => $price];
        if ($billingCycle) {
            $body['billing_cycle'] = $billingCycle;
        }
        if ($trialPeriod) {
            $body['trial_period'] = $trialPeriod;
        }

        return $this->request('POST', '/v1/products', $body);
    }

    // ── Customer portal ──────────────────────────────────────────────
    // docs.bachs.io/api-reference/customer-sessions/create-a-customer-portal-session,
    // confirmed live 2026-08-23. Returns a short-lived, pre-authenticated
    // URL -- generate a fresh one per request, never store or reuse it.
    public function createPortalSession(string $customerId): array
    {
        return $this->request('POST', "/v1/customers/{$customerId}/portal-sessions");
    }

    // ── Payments (reconciliation fallback) ───────────────────────────
    // docs.bachs.io/api-reference/payments -- a pull-based source of truth
    // independent of webhooks, for catching anything a missed/failed
    // webhook delivery would otherwise silently drop.
    public function listPayments(array $query = []): array
    {
        $qs = $query ? ('?' . http_build_query($query)) : '';
        $result = $this->request('GET', '/v1/payments' . $qs);

        return $result['items'] ?? [];
    }

    public function getPayment(string $paymentId): array
    {
        return $this->request('GET', "/v1/payments/{$paymentId}");
    }

    // ── Subscriptions ─────────────────────────────────────────────────
    // docs.bachs.io/api-reference/subscriptions -- created implicitly by a
    // checkout for a recurring (billing_cycle-bearing) product, never via a
    // direct "create" call. These cover the rest of the lifecycle.
    public function listSubscriptions(array $query = []): array
    {
        $qs = $query ? ('?' . http_build_query($query)) : '';
        $result = $this->request('GET', '/v1/subscriptions' . $qs);

        return $result['items'] ?? [];
    }

    public function getSubscription(string $subscriptionId): array
    {
        return $this->request('GET', "/v1/subscriptions/{$subscriptionId}");
    }

    public function updateSubscription(string $subscriptionId, array $body): array
    {
        return $this->request('PATCH', "/v1/subscriptions/{$subscriptionId}", $body);
    }

    public function cancelSubscription(string $subscriptionId, bool $atPeriodEnd = true): array
    {
        return $this->request('POST', "/v1/subscriptions/{$subscriptionId}/cancel", [
            'cancel_at_period_end' => $atPeriodEnd,
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
