<?php

use Perfexcrm\Bachs\BachsClient;
use Perfexcrm\Bachs\BachsSubscriptionsClient;

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * All Bachs subscription business logic lives here, deliberately kept out of
 * Bachs_gateway.php so the two files never need editing at the same time.
 * Bachs_gateway::process_webhook_event() only forwards 'customer.subscription.*'
 * envelopes into handle_webhook_event() below and returns.
 *
 * Facts baked in here, all confirmed live against the real Bachs sandbox on
 * 2026-08-23 (not from docs alone):
 *
 *  - There is no "create subscription" endpoint. A subscription is created
 *    implicitly when a checkout session is paid for a product that carries a
 *    billing_cycle. The sandbox confirmed this: a checkout against such a
 *    product came back with "mode":"subscription" and a "recurring" block,
 *    with no subscription-specific request parameter involved at all.
 *  - Recurring products are fixed-priced, and a fixed-priced product REJECTS
 *    the per-line `amount` override that BachsClient::createCheckoutSession()
 *    always sends ("Product '...' has fixed pricing; amount must not be
 *    provided"). Hence the separate BachsSubscriptionsClient for that one call.
 *  - `quantity` on the cart line is honoured and multiplies the recurring
 *    amount (quantity 3 against a $5.00/mo product returned "15.00").
 *  - trial_period on the product is accepted and echoed back.
 *  - Subscription READ/WRITE endpoints require API-key scopes the currently
 *    configured keys do NOT have: /v1/subscriptions returned
 *    "API key missing required scope: subscriptions:read". Everything that
 *    touches those endpoints (sync, cancel, resume, portal) therefore degrades
 *    to a logged, surfaced error rather than a fatal -- see api_scope_hint().
 */
class Bachs_subscriptions_gateway
{
    /**
     * Same fixed endpoints Bachs_gateway uses. Duplicated rather than shared
     * because they are private consts there, and reaching into that file to
     * widen them would be exactly the edit this fork exists to avoid.
     */
    private const LIVE_BASE_URL = 'https://api.bachs.io';

    private const TEST_BASE_URL = 'https://sandbox-api.bachs.io';

    /**
     * Bachs subscriptions are USD-only today -- there is no NGN (or any other
     * currency) recurring billing. Note the sandbox does NOT enforce this at
     * product-creation time (an NGN product carrying a billing_cycle was
     * accepted, HTTP 201), so nothing upstream will stop staff creating a
     * subscription that cannot actually bill. This constant is that guard.
     */
    public const SUPPORTED_CURRENCY = 'USD';

    public const INTERVALS = ['day', 'week', 'month', 'year'];

    protected $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->library('bachs_gateway');
        $this->ci->load->model('bachs/bachs_subscriptions_model');
    }

    // ── Environment ──────────────────────────────────────────────────

    public function is_test_mode(): bool
    {
        return $this->ci->bachs_gateway->is_test_mode();
    }

    public function mode(): string
    {
        return $this->is_test_mode() ? 'test' : 'live';
    }

    public function client(): BachsClient
    {
        return $this->ci->bachs_gateway->make_client();
    }

    private function checkout_client(): BachsSubscriptionsClient
    {
        $baseUrl = $this->is_test_mode() ? self::TEST_BASE_URL : self::LIVE_BASE_URL;
        $apiKey  = $this->ci->bachs_gateway->decryptSetting(
            $this->is_test_mode() ? 'test_api_key' : 'live_api_key'
        );

        return new BachsSubscriptionsClient($baseUrl, $apiKey);
    }

    /**
     * Turns the one API failure staff are most likely to hit into an
     * actionable sentence instead of a raw scope string.
     */
    public function api_scope_hint(string $message): string
    {
        if (stripos($message, 'missing required scope') === false) {
            return $message;
        }

        return $message . ' — regenerate the Bachs API key for this environment '
            . 'with the subscriptions scopes (read and write) enabled, then paste it '
            . 'into Settings → Payment Gateways → Bachs.io.';
    }

    // ── Product resolution ───────────────────────────────────────────

    /**
     * A recurring Bachs product is the price. Since the price is baked into
     * the product, any change to amount/currency/interval/trial needs a NEW
     * product -- so the row carries a signature of exactly those fields and
     * a product is re-created whenever the signature moves.
     */
    public function product_signature($subscription): string
    {
        return sha1(implode('|', [
            strtoupper((string) $subscription->currency),
            number_format((float) $subscription->amount, 2, '.', ''),
            (string) $subscription->billing_interval,
            (int) $subscription->billing_frequency,
            (string) ($subscription->trial_interval ?? ''),
            (int) ($subscription->trial_frequency ?? 0),
            $this->mode(),
        ]));
    }

    /**
     * Returns the Bachs product id to check out against, creating it on first
     * use (and re-creating it if the pricing shape changed since last time).
     */
    public function resolve_product_id($subscription): string
    {
        $signature = $this->product_signature($subscription);

        if (!empty($subscription->bachs_product_id) && $subscription->product_signature === $signature) {
            return $subscription->bachs_product_id;
        }

        $billingCycle = [
            'interval'  => $subscription->billing_interval,
            'frequency' => (int) $subscription->billing_frequency,
        ];

        $trialPeriod = null;
        if (!empty($subscription->trial_interval) && (int) $subscription->trial_frequency > 0) {
            $trialPeriod = [
                'interval'  => $subscription->trial_interval,
                'frequency' => (int) $subscription->trial_frequency,
            ];
        }

        $created = $this->client()->createFixedProduct(
            $subscription->name . ' (' . $this->interval_label($subscription) . ')',
            $this->plain_description($subscription),
            strtoupper($subscription->currency),
            number_format((float) $subscription->amount, 2, '.', ''),
            [],
            $billingCycle,
            $trialPeriod
        );

        if (empty($created['id'])) {
            throw new \RuntimeException('Bachs did not return a product id');
        }

        $this->ci->bachs_subscriptions_model->update($subscription->id, [
            'bachs_product_id'  => $created['id'],
            'product_signature' => $signature,
        ]);

        return $created['id'];
    }

    // ── Checkout ─────────────────────────────────────────────────────

    /**
     * Builds (or rebuilds) the checkout the client is sent to. Returns the
     * checkout_url. Throws on any API rejection; callers surface it.
     */
    public function create_checkout($subscription): string
    {
        if (strtoupper((string) $subscription->currency) !== self::SUPPORTED_CURRENCY) {
            throw new \RuntimeException(
                'Bachs subscriptions are ' . self::SUPPORTED_CURRENCY . '-only; this subscription is '
                . strtoupper((string) $subscription->currency)
            );
        }

        $productId = $this->resolve_product_id($subscription);

        [$email, $name] = $this->client_identity($subscription->clientid);

        // Bachs enforces reference uniqueness for the lifetime of the whole
        // organization (not just while a checkout is open) -- proven the hard
        // way on the invoice flow, where reusing a bare invoice id
        // permanently broke every retry past the first. Same rule applies
        // here, so the reference is unique per attempt and the real link back
        // is metadata, never the reference string.
        $reference = 'bsub' . $subscription->id . '-' . bin2hex(random_bytes(4));

        $session = $this->checkout_client()->createSubscriptionCheckout(
            $productId,
            (int) ($subscription->quantity ?: 1),
            $email,
            $name,
            $reference,
            [
                'bachs_subscription_hash' => (string) $subscription->hash,
                'bachs_subscription_ref'  => (string) $subscription->id,
            ],
            site_url('bachs/bachs_subscription/success/' . $subscription->hash),
            site_url('bachs/bachs_subscription/index/' . $subscription->hash)
        );

        if (empty($session['checkout_url'])) {
            throw new \RuntimeException('Bachs did not return a checkout URL');
        }

        // "mode":"subscription" is Bachs's own confirmation that it read the
        // product as recurring. If it comes back as a one-off, the product is
        // misconfigured and paying it would charge once and never again --
        // far better to fail loudly here than to silently sell a one-time
        // payment as a subscription.
        if (isset($session['mode']) && $session['mode'] !== 'subscription') {
            throw new \RuntimeException(
                'Bachs treated this checkout as "' . $session['mode'] . '", not a subscription — '
                . 'the product is missing its billing cycle'
            );
        }

        $this->ci->bachs_subscriptions_model->update($subscription->id, [
            'bachs_checkout_id'   => $session['checkout_id'] ?? null,
            'checkout_reference'  => $reference,
            'in_test_environment' => $this->is_test_mode() ? 1 : 0,
        ]);

        return $session['checkout_url'];
    }

    // ── Lifecycle ────────────────────────────────────────────────────

    public function cancel($subscription, bool $atPeriodEnd = true): void
    {
        if (empty($subscription->bachs_subscription_id)) {
            throw new \RuntimeException('This subscription has never been activated on Bachs.');
        }

        $remote = $this->client()->cancelSubscription($subscription->bachs_subscription_id, $atPeriodEnd);

        $update = ['cancel_at_period_end' => $atPeriodEnd ? 1 : 0];

        if ($atPeriodEnd) {
            // Keep billing until the paid-for period actually runs out.
            $update['ends_at'] = $this->to_datetime(
                $remote['current_period_end'] ?? $subscription->current_period_end
            );
        } else {
            // The webhook will confirm, but set it now so a staff refresh
            // doesn't still show the subscription as live.
            $update['status']      = 'canceled';
            $update['canceled_at'] = date('Y-m-d H:i:s');
            $update['ends_at']     = date('Y-m-d H:i:s');
        }

        $this->ci->bachs_subscriptions_model->update($subscription->id, $update);

        log_activity('Bachs subscription canceled [ID: ' . $subscription->id
            . ', Bachs: ' . $subscription->bachs_subscription_id
            . ', at period end: ' . ($atPeriodEnd ? 'yes' : 'no') . ']');
    }

    /**
     * "Resume" only ever means clearing a pending end-of-period cancelation.
     * A subscription that has genuinely ended cannot be revived -- the client
     * has to subscribe again, which is why the admin view hides Resume once
     * status is canceled.
     */
    public function resume($subscription): void
    {
        if (empty($subscription->bachs_subscription_id)) {
            throw new \RuntimeException('This subscription has never been activated on Bachs.');
        }

        $this->client()->updateSubscription($subscription->bachs_subscription_id, [
            'cancel_at_period_end' => false,
        ]);

        $this->ci->bachs_subscriptions_model->update($subscription->id, [
            'cancel_at_period_end' => 0,
            'ends_at'              => null,
        ]);

        log_activity('Bachs subscription resumed [ID: ' . $subscription->id . ']');
    }

    /**
     * Pull-based reconciliation, independent of webhooks -- the safety net for
     * any event that was never delivered, or that failed and dead-lettered.
     */
    public function sync($subscription): void
    {
        if (empty($subscription->bachs_subscription_id)) {
            throw new \RuntimeException('This subscription has never been activated on Bachs.');
        }

        $remote = $this->client()->getSubscription($subscription->bachs_subscription_id);

        $this->apply_remote($subscription, $remote);
    }

    public function portal_url($subscription): string
    {
        if (empty($subscription->bachs_customer_id)) {
            throw new \RuntimeException('No Bachs customer is linked to this subscription yet.');
        }

        // Deliberately never stored: portal URLs are short-lived and
        // pre-authenticated, so a cached one is both broken and a leak.
        $session = $this->client()->createPortalSession($subscription->bachs_customer_id);

        $url = $session['url'] ?? $session['portal_url'] ?? null;

        if (empty($url)) {
            throw new \RuntimeException('Bachs did not return a portal URL');
        }

        return $url;
    }

    // ── Webhooks ─────────────────────────────────────────────────────

    /**
     * Entry point for every 'customer.subscription.*' event, called from
     * Bachs_gateway::process_webhook_event()'s dispatch stub. Idempotency and
     * mark_processed()/mark_failed() are the caller's job (integration_runtime
     * already handles both); this throws on anything it cannot reconcile so
     * the event lands in 'failed' and gets retried rather than vanishing.
     */
    public function handle_webhook_event(array $envelope): void
    {
        $type = (string) ($envelope['type'] ?? '');
        $data = $envelope['data'] ?? [];

        if (!is_array($data) || empty($data['id'])) {
            throw new \RuntimeException('Bachs subscription event carries no subscription object');
        }

        $subscription = $this->resolve_local($data);

        if (!$subscription) {
            // Not an error worth retrying forever: a subscription created
            // directly in the Bachs dashboard, or belonging to another system
            // sharing this organization, legitimately has no local record.
            log_activity('Bachs subscription webhook for unknown subscription ' . $data['id'] . ' (' . $type . ') — ignored');

            return;
        }

        $forceCanceled = ($type === 'customer.subscription.deleted');

        $this->apply_remote($subscription, $data, $forceCanceled);
    }

    private function resolve_local(array $data)
    {
        $model = $this->ci->bachs_subscriptions_model;

        if ($local = $model->get_by_bachs_id($data['id'])) {
            return $local;
        }

        $hash = $data['metadata']['bachs_subscription_hash'] ?? null;
        if (!empty($hash) && ($local = $model->get_by_hash($hash))) {
            return $local;
        }

        // Last resort. Metadata set on a checkout session is not guaranteed
        // to survive onto the subscription object Bachs later creates from
        // it, so fall back to the newest not-yet-activated local record for
        // the same product.
        $productId = $data['product']['id'] ?? ($data['items'][0]['product_id'] ?? null);

        return $model->get_pending_by_product($productId);
    }

    /**
     * The single place a remote subscription object is written into the local
     * row, shared by the webhook path and the pull-based sync path so the two
     * can never drift apart.
     */
    private function apply_remote($subscription, array $remote, bool $forceCanceled = false): void
    {
        $previousStatus = (string) $subscription->status;

        $status = $forceCanceled ? 'canceled' : strtolower((string) ($remote['status'] ?? $previousStatus));

        $update = [
            'bachs_subscription_id' => $remote['id'] ?? $subscription->bachs_subscription_id,
            'status'                => $status,
            'cancel_at_period_end'  => !empty($remote['cancel_at_period_end']) ? 1 : 0,
            'current_period_start'  => $this->to_datetime($remote['current_period_start'] ?? null),
            'current_period_end'    => $this->to_datetime($remote['current_period_end'] ?? null),
            'next_billed_at'        => $this->to_datetime($remote['next_billed_at'] ?? null),
            'trial_end'             => $this->to_datetime($remote['trial_end'] ?? null),
            'canceled_at'           => $this->to_datetime($remote['canceled_at'] ?? null),
        ];

        // The customer object arrives inline on the subscription; keeping its
        // id is what later makes a customer-portal session possible.
        $customerId = $remote['customer']['customer_id'] ?? ($remote['customer']['id'] ?? null);
        if (!empty($customerId)) {
            $update['bachs_customer_id'] = $customerId;
        }

        if (!empty($remote['quantity'])) {
            $update['quantity'] = (int) $remote['quantity'];
        }

        if (empty($subscription->date_subscribed) && in_array($status, ['trialing', 'active'], true)) {
            $update['date_subscribed'] = date('Y-m-d H:i:s');
        }

        if ($status === 'canceled') {
            $update['canceled_at'] = $update['canceled_at'] ?: date('Y-m-d H:i:s');
            $update['ends_at']     = $update['ends_at'] ?? date('Y-m-d H:i:s');
        }

        $this->ci->bachs_subscriptions_model->update($subscription->id, $update);

        log_activity('Bachs subscription updated from ' . ($forceCanceled ? 'deletion event' : 'Bachs')
            . ' [ID: ' . $subscription->id . ', status: ' . $previousStatus . ' → ' . $status . ']');

        if ($status !== $previousStatus) {
            $this->notify_status_change($subscription, $previousStatus, $status);
        }
    }

    // ── Client notifications ─────────────────────────────────────────

    /**
     * WhatsApp, via the already-live Baileys bridge in the rest_api module.
     * Those are plain global functions in the same PHP process, so this is a
     * direct call, not an HTTP hop. Guarded by function_exists so this module
     * never hard-depends on that one being installed.
     */
    private function notify_status_change($subscription, string $from, string $to): void
    {
        if (!function_exists('baileys_send_to_client')) {
            return;
        }

        $name = function_exists('baileys_client_first_name')
            ? baileys_client_first_name($subscription->clientid)
            : 'there';

        $signoff = function_exists('baileys_signoff') ? baileys_signoff() : '';

        $link    = site_url('bachs/bachs_subscription/index/' . $subscription->hash);
        $amount  = $this->amount_label($subscription);
        $context = ['related_type' => 'bachs_subscription', 'related_id' => (int) $subscription->id];

        if (in_array($to, ['active', 'trialing'], true) && !in_array($from, ['active', 'trialing'], true)) {
            $msg = "Hello " . $name . ",\n\n"
                 . "Your subscription is now active - thank you for setting it up.\n\n"
                 . "Subscription: " . $subscription->name . "\n"
                 . "Billing: " . $amount . "\n\n"
                 . "You can view it any time here:\n" . $link . $signoff;

            baileys_send_to_client($subscription->clientid, $msg, 'subscription_started', $context);

            return;
        }

        if (in_array($to, ['past_due', 'unpaid'], true)) {
            $msg = "Hello " . $name . ",\n\n"
                 . "We weren't able to take the latest payment for one of your subscriptions. "
                 . "No action has been taken on your account yet - it usually just means the card needs updating.\n\n"
                 . "Subscription: " . $subscription->name . "\n"
                 . "Billing: " . $amount . "\n\n"
                 . "You can review it here:\n" . $link . $signoff;

            baileys_send_to_client($subscription->clientid, $msg, 'subscription_payment_failed', $context);

            return;
        }

        if ($to === 'canceled') {
            $msg = "Hello " . $name . ",\n\n"
                 . "Your subscription has been canceled and you won't be billed for it again.\n\n"
                 . "Subscription: " . $subscription->name . "\n\n"
                 . "If this wasn't what you expected, just reply here and we'll sort it out.\n" . $signoff;

            baileys_send_to_client($subscription->clientid, $msg, 'subscription_canceled', $context);
        }
    }

    // ── Presentation helpers ─────────────────────────────────────────

    public function interval_label($subscription): string
    {
        $frequency = (int) $subscription->billing_frequency;
        $interval  = (string) $subscription->billing_interval;

        if ($frequency <= 1) {
            return 'every ' . $interval;
        }

        return 'every ' . $frequency . ' ' . $interval . 's';
    }

    public function amount_label($subscription): string
    {
        $total = (float) $subscription->amount * (int) ($subscription->quantity ?: 1);

        return app_format_money($total, strtoupper((string) $subscription->currency))
            . ' ' . $this->interval_label($subscription);
    }

    public function trial_label($subscription): string
    {
        if (empty($subscription->trial_interval) || (int) $subscription->trial_frequency <= 0) {
            return '';
        }

        $frequency = (int) $subscription->trial_frequency;

        return $frequency . ' ' . $subscription->trial_interval . ($frequency > 1 ? 's' : '') . ' free trial';
    }

    private function plain_description($subscription): string
    {
        $text = trim(strip_tags(str_replace(['<br />', '<br>'], "\n", (string) $subscription->description)));

        return $text !== '' ? mb_substr($text, 0, 500) : $subscription->name;
    }

    private function client_identity($clientId): array
    {
        $this->ci->load->model('clients_model');

        $email = null;
        $name  = null;

        $contact = $this->ci->clients_model->get_contact(get_primary_contact_user_id($clientId));

        if (!$contact) {
            $contacts = $this->ci->clients_model->get_contacts($clientId);
            $contact  = !empty($contacts) ? (object) $contacts[0] : null;
        }

        if ($contact) {
            $email = $contact->email ?? null;
            $name  = trim(($contact->firstname ?? '') . ' ' . ($contact->lastname ?? ''));
        }

        return [
            $email ?: 'billing@airixmedia.com',
            $name ?: 'Airix Media Client',
        ];
    }

    /**
     * Bachs timestamps are ISO-8601 with a Z suffix; the DB columns are plain
     * DATETIME. Anything unparseable becomes null rather than 1970-01-01.
     */
    private function to_datetime($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int) $value);
        }

        $ts = strtotime((string) $value);

        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
