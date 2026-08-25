<?php

use Perfexcrm\Bachs\BachsAmounts;
use Perfexcrm\Bachs\BachsClient;

defined('BASEPATH') or exit('No direct script access allowed');

class Bachs_gateway extends App_gateway
{
    /**
     * Fixed API endpoints, not per-merchant configuration -- every Bachs
     * account uses the exact same two base URLs, so there is nothing for
     * a "Base URL" settings field to ever legitimately change. Previously
     * exposed as editable settings; removed after review confirmed they
     * were pure unnecessary complexity.
     */
    private const LIVE_BASE_URL = 'https://api.bachs.io';
    private const TEST_BASE_URL = 'https://sandbox-api.bachs.io';

    /**
     * The checkout (frontend) domain is a DIFFERENT host from the API base
     * URL above (confirmed live: a real checkout_url was
     * https://sandbox-checkout.bachs.io/... while the API itself is
     * sandbox-api.bachs.io) -- bachs.js's own Checkout.open() rejects any
     * checkoutUrl whose origin doesn't match the baseUrl passed to
     * Initialize(), so the overlay view needs this, separately from the
     * API base URL.
     */
    private const LIVE_CHECKOUT_ORIGIN = 'https://checkout.bachs.io';
    private const TEST_CHECKOUT_ORIGIN = 'https://sandbox-checkout.bachs.io';

    public function __construct()
    {
        parent::__construct();
        $this->ci = &get_instance();
        $this->setId('bachs');
        $this->setName('Bachs.io');

        $this->setSettings([
            [
                'name'  => 'test_mode_enabled',
                'type'  => 'yes_no',
                'default_value' => 1,
                'label' => 'settings_paymentmethod_testing_mode',
                'after' => '<hr><h5><strong>Sandbox Environment</strong></h5><p class="text-muted">Used while Test Mode is enabled above.</p>',
            ],
            [
                'name'  => 'test_api_key',
                'encrypted' => true,
                'label' => 'Sandbox API Key (sk_sandbox_...)',
            ],
            [
                'name'  => 'test_webhook_secret',
                'encrypted' => true,
                'label' => 'Sandbox Webhook Signing Secret',
                'after' => '<hr><h5><strong>Live Environment</strong></h5><p class="text-muted">Used while Test Mode is disabled above.</p>',
            ],
            [
                'name'  => 'live_api_key',
                'encrypted' => true,
                'label' => 'Live API Key (sk_live_...)',
            ],
            [
                'name'  => 'live_webhook_secret',
                'encrypted' => true,
                'label' => 'Live Webhook Signing Secret',
                'after' => '<hr>',
            ],
            [
                // The settings view (application/views/admin/settings/includes/
                // payment_gateways.php) only renders 'yes_no'/'input'/'textarea'
                // option types -- a 'select' type is silently never rendered at
                // all (confirmed 2026-07-20, the reason this field never
                // appeared). Hosted vs. overlay is a true binary, so a yes_no
                // toggle covers it without touching that core view file.
                'name'  => 'use_overlay_checkout',
                'type'  => 'yes_no',
                'default_value' => 0,
                'label' => 'Use Overlay Checkout (modal on this page, instead of redirecting to Bachs)',
            ],
            [
                'name'  => 'currencies',
                'label' => 'settings_paymentmethod_currencies',
                'default_value' => 'NGN,USD',
            ],
        ]);

        hooks()->add_action('before_render_payment_gateway_settings', 'bachs_gateway_notice');
    }

    /**
     * Only ever creates a checkout session -- a browser landing back on
     * the success URL is NEVER treated as payment confirmation. Only a
     * verified, signature-checked Bachs webhook (Bachs_webhook::receive())
     * records a payment. See the master plan's "hard rule" for Bachs
     * reconciliation. This holds regardless of checkout_type: hosted and
     * overlay both use the exact same checkout_url and the exact same
     * webhook-only confirmation.
     */
    public function process_payment(array $data): void
    {
        $this->ci->load->model('bachs/bachs_sessions_model');

        // Guards against the invoice's own "Pay Now" form being resubmitted
        // (a browser reload of a POST-loaded page, a replayed request, a
        // stale bookmark) after the invoice is already fully paid -- without
        // this, a resubmission would happily create a brand new Bachs
        // checkout for an invoice that owes nothing.
        $this->ci->load->helper('invoices');
        if ((float) get_invoice_total_left_to_pay($data['invoice']->id, $data['invoice']->total) <= 0.0) {
            redirect(site_url('invoice/' . $data['invoiceid'] . '/' . $data['invoice']->hash));
            return;
        }

        $currency = strtoupper($data['invoice']->currency_name);
        if (!in_array($currency, $this->supported_currencies(), true)) {
            set_alert('danger', _l('bachs_unsupported_currency'));
            redirect(site_url('invoice/' . $data['invoiceid'] . '/' . $data['invoice']->hash));
            return;
        }

        $client    = $this->make_client();
        $productId = $this->resolve_product_id($client, $currency);

        if (empty($productId)) {
            set_alert('danger', _l('bachs_missing_product_id'));
            redirect(site_url('invoice/' . $data['invoiceid'] . '/' . $data['invoice']->hash));
            return;
        }
        $returnUrl = site_url('invoice/' . $data['invoiceid'] . '/' . $data['invoice']->hash);

        // In overlay mode, Bachs's own hosted checkout page redirects to
        // success_url *inside the iframe* once it finishes its own
        // completion countdown, regardless of embed context -- pointing
        // that straight at the invoice page meant it reloaded embedded
        // inside the modal, often before the webhook had processed,
        // looking exactly like the payment had never happened. Route
        // through a small confirmation page instead, which breaks out of
        // the iframe after a short delay. Hosted mode has no iframe to
        // escape, so it keeps going straight to the invoice.
        $successUrl = $this->getSetting('use_overlay_checkout') == '1'
            ? site_url('bachs/bachs_return/complete/' . $data['invoiceid'] . '/' . $data['invoice']->hash)
            : $returnUrl;

        // Reuse an existing open checkout for this invoice rather than
        // creating a new one on every retry (ported from the original TS
        // checkout service).
        $existing = $this->ci->bachs_sessions_model->get_open_for_invoice($data['invoice']->id);
        if ($existing) {
            try {
                $status = $client->getCheckoutStatus($existing->bachs_checkout_id);
                if (strtolower((string) ($status['status'] ?? '')) === 'open' && $existing->checkout_url) {
                    $this->deliver_checkout($existing->checkout_url, $returnUrl);
                    return;
                }
            } catch (\Throwable $e) {
                // fall through to creating a fresh session
            }
        }

        // The contact's actual name, not their email address a second time --
        // createCheckoutSession()'s 4th param is genuinely 'name', but this
        // used to pass $email there too, so every Bachs checkout and every
        // resulting customer record on Bachs's own side showed the client's
        // email address as their "name".
        $email = null;
        $name = null;
        if (is_client_logged_in()) {
            $contact = $this->ci->clients_model->get_contact(get_contact_user_id());
            if (!empty($contact->email)) {
                $email = $contact->email;
            }
            if ($contact) {
                $name = trim(($contact->firstname ?? '') . ' ' . ($contact->lastname ?? ''));
            }
        } else {
            $contacts = $this->ci->clients_model->get_contacts($data['invoice']->clientid);
            if (count($contacts) === 1 && !empty($contacts[0]['email'])) {
                $email = $contacts[0]['email'];
            }
            if (count($contacts) === 1) {
                $name = trim(($contacts[0]['firstname'] ?? '') . ' ' . ($contacts[0]['lastname'] ?? ''));
            }
        }
        $email = $email ?: 'billing@airixmedia.com';
        $name = $name ?: 'Airix Media Client';

        $amountMajor = BachsAmounts::toMajorUnitsString((int) round($data['amount'] * 100));

        // Bachs enforces reference uniqueness for the lifetime of the
        // organization, not just while a checkout is open (confirmed live,
        // 2026-07-20: a real "Reference '21' already exists for this
        // organization" rejection on a retry after the first session had
        // expired). Reusing the bare invoice id as reference therefore
        // permanently breaks any retry past the first attempt. The
        // invoice is instead resolved from metadata.invoice_id in the
        // webhook (see process_webhook_event()), so reference only needs
        // to stay human-traceable, not machine-parseable.
        $reference = 'inv' . $data['invoice']->id . '-' . bin2hex(random_bytes(4));

        try {
            $session = $client->createCheckoutSession(
                $productId,
                $amountMajor,
                $email,
                $name,
                $reference,
                ['invoice_id' => (int) $data['invoiceid'], 'invoice_hash' => (string) $data['invoice']->hash],
                $successUrl,
                $returnUrl
            );
        } catch (\Throwable $e) {
            log_activity('Bachs checkout creation failed: ' . $e->getMessage());
            set_alert('danger', _l('bachs_checkout_failed'));
            redirect($returnUrl);
            return;
        }

        $this->ci->bachs_sessions_model->create($data['invoice']->id, $session['checkout_id'], $session['checkout_url'] ?? null, (string) ($session['status'] ?? 'created'));

        $this->deliver_checkout($session['checkout_url'], $returnUrl);
    }

    /**
     * Hosted and overlay share the exact same checkout_url (confirmed
     * directly against docs.bachs.io/guides/checkout/overlay-checkout) --
     * the only difference is whether the browser is redirected to it
     * server-side, or the same URL is opened client-side in a modal via
     * bachs.js. $invoiceUrl is where the overlay navigates back to (via a
     * real GET, never reload()) once the checkout closes for any reason.
     */
    private function deliver_checkout(string $checkoutUrl, string $invoiceUrl): void
    {
        if ($this->getSetting('use_overlay_checkout') == '1') {
            $checkoutOrigin = $this->is_test_mode() ? self::TEST_CHECKOUT_ORIGIN : self::LIVE_CHECKOUT_ORIGIN;
            $this->ci->load->view('bachs/overlay', [
                'checkout_url'    => $checkoutUrl,
                'checkout_origin' => $checkoutOrigin,
                'invoice_url'     => $invoiceUrl,
            ]);
            return;
        }

        header('Location: ' . $checkoutUrl);
    }

    /**
     * Resolves the correct base URL + API key pair for whichever mode
     * (test/live) is currently enabled -- matching the exact pattern the
     * stock `paystack` module already uses for its own test_mode_enabled
     * toggle, confirmed by reading Paystack_gateway.php directly. Also
     * confirmed directly against docs.bachs.io/integrate/sandbox that
     * sandbox and production are genuinely separate on every axis (base
     * URL, API key, webhook endpoint, signing secret) -- not a single
     * URL with prefix-based routing as an earlier, less authoritative
     * source (a third-party SDK's README) had suggested.
     */
    public function make_client(): BachsClient
    {
        if ($this->is_test_mode()) {
            $baseUrl = self::TEST_BASE_URL;
            $apiKey  = $this->decryptSetting('test_api_key');
        } else {
            $baseUrl = self::LIVE_BASE_URL;
            $apiKey  = $this->decryptSetting('live_api_key');
        }

        return new BachsClient($baseUrl, $apiKey);
    }

    public function is_test_mode(): bool
    {
        return $this->getSetting('test_mode_enabled') == '1';
    }

    /**
     * The gateway settings already had a 'currencies' field
     * (settings_paymentmethod_currencies, default 'NGN,USD') but nothing
     * ever read it -- process_payment() and the webhook handler both had a
     * hardcoded NGN/USD-only check instead, so adding a currency here
     * required a code change even though the setting existed for exactly
     * this. Now the single source of truth: add a currency to this comma
     * list in the Bachs gateway settings and it's accepted end-to-end,
     * still guarded because resolve_product_id()'s product-creation call
     * to Bachs will itself reject a currency Bachs doesn't actually settle
     * in -- that failure is already caught and surfaced as a clean alert,
     * not a broken payment.
     */
    private function supported_currencies(): array
    {
        $raw = (string) $this->getSetting('currencies');
        $currencies = array_filter(array_map('trim', explode(',', strtoupper($raw))));

        return $currencies ?: ['NGN', 'USD'];
    }

    /**
     * No manual product-id setting -- the module manages its own reusable
     * custom-amount product per currency entirely via the API, caching the
     * resolved id in a plain (non-UI, not user-editable) option keyed by
     * mode+currency. Confirmed live 2026-07-20: products don't carry across
     * sandbox/production, and this account already had two custom-price
     * products (one NGN, one USD) sitting in the sandbox from an earlier
     * build -- reused here rather than duplicated -- while the live account
     * had none, in which case one is created on first use.
     */
    private function resolve_product_id(BachsClient $client, string $currency): ?string
    {
        $optionName = 'paymentmethod_bachs_' . ($this->is_test_mode() ? 'test' : 'live') . '_' . strtolower($currency) . '_product_id';

        $cached = trim((string) get_option($optionName));
        if (!empty($cached)) {
            return $cached;
        }

        try {
            foreach ($client->listProducts() as $product) {
                $matchesCurrency = strtoupper((string) ($product['price']['currency'] ?? '')) === $currency;
                $isCustomPriced  = ($product['price']['price_type'] ?? '') === 'custom';
                $isActive        = ($product['status'] ?? '') === 'active';

                if ($matchesCurrency && $isCustomPriced && $isActive && !empty($product['id'])) {
                    update_option($optionName, $product['id']);
                    return $product['id'];
                }
            }
        } catch (\Throwable $e) {
            log_activity('Bachs product lookup failed for ' . $currency . ': ' . $e->getMessage());
        }

        try {
            $created = $client->createProduct(
                'Airix Media Invoice (' . $currency . ')',
                'Custom-amount checkout for ' . $currency . '-billed Airix Media OS invoices.',
                $currency
            );

            if (!empty($created['id'])) {
                update_option($optionName, $created['id']);
                return $created['id'];
            }
        } catch (\Throwable $e) {
            log_activity('Bachs product auto-create failed for ' . $currency . ': ' . $e->getMessage());
        }

        return null;
    }

    public function webhook_secret(): string
    {
        return $this->is_test_mode()
            ? $this->decryptSetting('test_webhook_secret')
            : $this->decryptSetting('live_webhook_secret');
    }

    /**
     * The single source of truth for turning a verified Bachs webhook
     * envelope into a Perfex payment record. Called from two places that
     * must never drift out of sync with each other: Bachs_webhook::receive()
     * (the live HTTP path) and Bachs_events_model::retry_due_events() (the
     * self-contained cron retry / manual-replay path, hooked to
     * after_cron_run in bachs.php). Throws on any rejection;
     * callers are responsible for mark_processed()/mark_failed().
     */
    /**
     * Dispatch across the full 22-event Bachs webhook catalog (Checkout,
     * Payments, Subscriptions, Invoices, Withdrawals, Refunds, Disputes,
     * Conversions, Customers -- confirmed against docs.bachs.io's webhook
     * reference, 2026-08-23). Only collection.*, checkout.*, refund.*,
     * dispute.*, customer.created/updated, and customer.subscription.* have
     * local business logic wired up -- everything else (payment.*,
     * invoice.*, withdrawal.*, conversion.*) is logged and acknowledged
     * cleanly rather than thrown on, since nothing in this install depends
     * on them yet and the listPayments()/getPayment() reconciliation sweep
     * covers payment-state drift independently of webhook delivery.
     */
    public function process_webhook_event(array $envelope): void
    {
        $type = $envelope['type'];

        if (strpos($type, 'customer.subscription.') === 0) {
            if (class_exists('Bachs_subscriptions_gateway')) {
                $this->ci->load->library('bachs_subscriptions_gateway');
                $this->ci->bachs_subscriptions_gateway->handle_webhook_event($envelope);
            } else {
                log_activity('Bachs webhook received for un-installed subscriptions feature: ' . $type);
            }
            return;
        }

        if ($type === 'collection.succeeded' || $type === 'collection.underpaid') {
            $this->handle_collection_event($envelope);
            return;
        }

        if (strpos($type, 'checkout.') === 0) {
            $this->ci->load->model('bachs/bachs_sessions_model');
            $this->ci->bachs_sessions_model->update_status($envelope['data']['checkout_id'] ?? '', 'closed');
            return;
        }

        if (strpos($type, 'refund.') === 0) {
            $this->handle_refund_event($envelope);
            return;
        }

        if (strpos($type, 'dispute.') === 0) {
            $this->handle_dispute_event($envelope);
            return;
        }

        if ($type === 'customer.created' || $type === 'customer.updated') {
            $this->handle_customer_event($envelope);
            return;
        }

        log_activity('Bachs webhook received, no handler wired: ' . $type);
    }

    private function handle_collection_event(array $envelope): void
    {
        $this->ci->load->model('bachs/bachs_sessions_model');
        $this->ci->load->model('bachs/bachs_transactions_model');
        $this->ci->load->model('invoices_model');
        $this->ci->load->helper('invoices');

        $type = $envelope['type'];

        $chargeId = (string) ($envelope['data']['charge_id'] ?? $envelope['data']['checkout_id'] ?? $envelope['id']);

        if ($this->ci->bachs_transactions_model->exists($chargeId)) {
            return;
        }

        // Resolved from metadata.invoice_id, not the checkout's own
        // reference string -- reference is now a unique-per-attempt value
        // (see process_payment()) precisely so it never collides with
        // Bachs's own permanent per-organization uniqueness constraint;
        // metadata is the real, stable link back to the invoice.
        $invoiceId = $envelope['data']['metadata']['invoice_id'] ?? null;
        if (empty($invoiceId) || !ctype_digit((string) $invoiceId)) {
            // A recurring/subscription charge has no Perfex invoice to link
            // to (subscriptions don't generate child invoices -- see the
            // subscriptions feature's own scope notes), so it legitimately
            // has no metadata.invoice_id. Recognized by a subscription_id
            // in metadata or a 'subscription' block on the charge itself.
            // Logged and acknowledged cleanly rather than thrown on --
            // throwing here would dead-letter every single subscription
            // renewal payment forever, since it can never gain an
            // invoice_id on retry. A genuinely malformed one-time-payment
            // event (no subscription context either) still throws, since
            // that really is unrecoverable data loss worth surfacing.
            $subscriptionId = $envelope['data']['metadata']['subscription_id']
                ?? $envelope['data']['subscription']['id']
                ?? $envelope['data']['subscription_id']
                ?? null;

            if (!empty($subscriptionId)) {
                log_activity('Bachs subscription renewal charge ' . $chargeId . ' for subscription ' . $subscriptionId . ' -- no local invoice to attach, payment not recorded in tblbachs_transactions');
                return;
            }

            throw new \RuntimeException('missing or non-numeric metadata.invoice_id on Bachs event');
        }

        $invoice = $this->ci->invoices_model->get((int) $invoiceId);
        if (!$invoice) {
            throw new \RuntimeException('no invoice with id ' . $invoiceId);
        }

        // settlement_amount is the NET amount owed against the invoice --
        // confirmed live 2026-07-20: a real card payment's "amount" field
        // was the GROSS customer-charged total including a Bachs
        // processing fee passed through to the customer ("fee_bearer":
        // "customer"), while "settlement_amount" was the exact NGN/USD
        // amount actually due. Using the gross amount here would reject
        // every fee-inclusive card payment as "exceeding the balance".
        // Falls back to the older amount/amount_paid fields for event
        // shapes that don't include settlement_amount.
        $amountStr = $envelope['data']['settlement_amount']
            ?? ($type === 'collection.underpaid' ? ($envelope['data']['amount_paid'] ?? null) : ($envelope['data']['amount'] ?? null));

        if (empty($amountStr) || !is_numeric($amountStr) || (float) $amountStr <= 0) {
            throw new \RuntimeException('missing or non-positive amount on Bachs event');
        }

        // Currency must match the invoice being paid, and must be a
        // currently-supported currency -- otherwise the invoice balance
        // gets decremented by a value denominated in the wrong currency.
        $eventCurrency = strtoupper((string) ($envelope['data']['currency'] ?? $invoice->currency_name));
        if (!in_array($eventCurrency, $this->supported_currencies(), true) || $eventCurrency !== strtoupper($invoice->currency_name)) {
            throw new \RuntimeException("currency mismatch: event {$eventCurrency} vs invoice {$invoice->currency_name}");
        }

        $amountMinor = BachsAmounts::toMinorUnits((string) $amountStr);
        $amountMajor = (float) $amountStr;

        // Defense in depth against a wrong/corrupted amount ever being
        // applied (a Bachs-side bug, or a compromised API key/secret) --
        // a verified signature proves the request came from Bachs, but not
        // that the amount is sane relative to what's actually owed. Allow
        // a small epsilon for float/currency rounding, not a blank check.
        $remaining = (float) get_invoice_total_left_to_pay($invoice->id, $invoice->total);
        if ($amountMajor > $remaining + 0.01) {
            throw new \RuntimeException(
                "amount {$amountMajor} exceeds invoice {$invoice->id}'s remaining balance {$remaining} -- rejected, needs manual review"
            );
        }

        $this->ci->bachs_transactions_model->record($chargeId, $invoice->id, $amountMinor, $eventCurrency, 'confirmed');

        $this->addPayment([
            'amount'        => $amountMajor,
            'invoiceid'     => $invoice->id,
            'paymentmethod' => 'Bachs',
            'transactionid' => $chargeId,
        ]);

        $checkoutId = $envelope['data']['checkout_id'] ?? null;
        if ($checkoutId) {
            $this->ci->bachs_sessions_model->update_status($checkoutId, 'completed');
        }
    }

    /**
     * Staff-initiated refund, called from Bachs_admin's refund action. The
     * refund is not applied to the local invoice/transaction record here --
     * Bachs refunds go through an async lifecycle (created -> paid/failed),
     * exactly like the payment side, so the authoritative state change
     * happens in handle_refund_event() off the refund.paid webhook, not at
     * request time. This call only initiates it and records the attempt.
     */
    public function create_refund(string $chargeId, ?string $amountMajor = null, string $reason = ''): array
    {
        $this->ci->load->model('bachs/bachs_refunds_model');

        $reference = 'rfd' . substr(preg_replace('/[^a-zA-Z0-9]/', '', $chargeId), 0, 12) . '-' . bin2hex(random_bytes(4));

        $result = $this->make_client()->refund($chargeId, $reference, $amountMajor, $reason);

        $refundId = (string) ($result['id'] ?? $reference);
        $this->ci->bachs_refunds_model->upsert($refundId, [
            'bachs_charge_id' => $chargeId,
            'invoice_id'      => $this->resolve_invoice_id_for_charge($chargeId),
            'amount_minor'    => $amountMajor !== null ? BachsAmounts::toMinorUnits($amountMajor) : 0,
            'currency'        => strtoupper((string) ($result['currency'] ?? '')),
            'status'          => (string) ($result['status'] ?? 'pending'),
            'reason'          => $reason,
        ]);

        return $result;
    }

    private function resolve_invoice_id_for_charge(string $chargeId): ?int
    {
        $row = $this->ci->db->select('invoice_id')
            ->where('bachs_charge_id', $chargeId)
            ->get(db_prefix() . 'bachs_transactions')
            ->row();

        return $row ? (int) $row->invoice_id : null;
    }

    private function handle_refund_event(array $envelope): void
    {
        $this->ci->load->model('bachs/bachs_refunds_model');
        $this->ci->load->model('bachs/bachs_transactions_model');

        $data      = $envelope['data'];
        $refundId  = (string) ($data['id'] ?? $envelope['id']);
        $chargeId  = (string) ($data['charge_id'] ?? '');
        $status    = strtolower((string) ($data['status'] ?? ''));
        $amountStr = $data['amount'] ?? null;

        $this->ci->bachs_refunds_model->upsert($refundId, array_filter([
            'bachs_charge_id' => $chargeId ?: null,
            'invoice_id'      => $chargeId ? $this->resolve_invoice_id_for_charge($chargeId) : null,
            'amount_minor'    => $amountStr !== null ? BachsAmounts::toMinorUnits((string) $amountStr) : null,
            'currency'        => !empty($data['currency']) ? strtoupper((string) $data['currency']) : null,
            'status'          => $status ?: null,
            'reason'          => $data['reason'] ?? null,
        ], fn ($v) => $v !== null));

        if ($envelope['type'] !== 'refund.paid' || empty($chargeId)) {
            return;
        }

        $transaction = $this->ci->bachs_transactions_model->exists($chargeId)
            ? $this->ci->db->where('bachs_charge_id', $chargeId)->get(db_prefix() . 'bachs_transactions')->row()
            : null;

        if (!$transaction) {
            log_activity('Bachs refund.paid for unknown charge ' . $chargeId);
            return;
        }

        $refundedMinor = $amountStr !== null
            ? (int) $transaction->refunded_amount_minor + BachsAmounts::toMinorUnits((string) $amountStr)
            : (int) $transaction->amount_minor;
        $refundStatus = $refundedMinor >= (int) $transaction->amount_minor ? 'full' : 'partial';

        $this->ci->db->where('bachs_charge_id', $chargeId)->update(db_prefix() . 'bachs_transactions', [
            'refunded_amount_minor' => $refundedMinor,
            'refund_status'         => $refundStatus,
        ]);

        $this->ci->load->model('invoices_model');
        $invoice = $this->ci->invoices_model->get((int) $transaction->invoice_id);
        if ($invoice) {
            log_activity('Bachs refund ' . $refundStatus . ' for invoice #' . $invoice->id . ' (charge ' . $chargeId . ')');

            if (function_exists('baileys_send_to_client')) {
                $amountLabel = number_format($refundedMinor / 100, 2) . ' ' . strtoupper((string) ($data['currency'] ?? $transaction->currency));
                baileys_send_to_client(
                    $invoice->clientid,
                    "Hi, we've processed a {$refundStatus} refund of {$amountLabel} for invoice #{$invoice->id}. It should reflect on your original payment method shortly.",
                    'bachs_refund_processed',
                    ['invoice_id' => $invoice->id, 'charge_id' => $chargeId]
                );
            }
        }
    }

    private function handle_dispute_event(array $envelope): void
    {
        $this->ci->load->model('bachs/bachs_disputes_model');
        $this->ci->load->model('bachs/bachs_transactions_model');

        $data       = $envelope['data'];
        $disputeId  = (string) ($data['id'] ?? $envelope['id']);
        $chargeId   = (string) ($data['charge_id'] ?? '');
        $amountStr  = $data['amount'] ?? null;

        $transaction = $chargeId
            ? $this->ci->db->where('bachs_charge_id', $chargeId)->get(db_prefix() . 'bachs_transactions')->row()
            : null;

        $this->ci->bachs_disputes_model->upsert($disputeId, array_filter([
            'bachs_charge_id' => $chargeId ?: null,
            'invoice_id'      => $transaction->invoice_id ?? null,
            'amount_minor'    => $amountStr !== null ? BachsAmounts::toMinorUnits((string) $amountStr) : null,
            'currency'        => !empty($data['currency']) ? strtoupper((string) $data['currency']) : null,
            'status'          => !empty($data['status']) ? strtolower((string) $data['status']) : null,
            'reason'          => $data['reason'] ?? null,
        ], fn ($v) => $v !== null));

        // Disputes need a human, always -- unlike refunds/payments there is
        // no automatic resolution path, so every dispute event notifies
        // staff (never the client) regardless of status.
        $invoiceLabel = $transaction ? ('#' . $transaction->invoice_id) : 'unknown';
        log_activity('Bachs dispute ' . $envelope['type'] . ' for invoice ' . $invoiceLabel . ' (charge ' . $chargeId . ', dispute ' . $disputeId . ')');
    }

    private function handle_customer_event(array $envelope): void
    {
        $data  = $envelope['data'];
        $email = trim((string) ($data['email'] ?? ''));
        $bachsCustomerId = (string) ($data['id'] ?? $envelope['id'] ?? '');

        if (empty($email) || empty($bachsCustomerId)) {
            return;
        }

        $client = $this->ci->db->select('tblclients.userid')
            ->from(db_prefix() . 'clients')
            ->join(db_prefix() . 'contacts', db_prefix() . 'contacts.userid = ' . db_prefix() . 'clients.userid')
            ->where('LOWER(' . db_prefix() . 'contacts.email)', strtolower($email))
            ->get()
            ->row();

        if (!$client) {
            return;
        }

        $this->ci->load->model('bachs/bachs_customers_model');
        $this->ci->bachs_customers_model->map((int) $client->userid, $bachsCustomerId, $this->is_test_mode() ? 'test' : 'live');
    }

    /**
     * Returns a fresh, short-lived pre-authenticated Bachs customer portal
     * URL for a Perfex client, or null if this client has no known Bachs
     * customer id yet (they've never completed a checkout, so Bachs has
     * never fired a customer.created event for them). Never cache the
     * returned URL -- generate one per request, per Bachs's own docs.
     */
    public function get_portal_url(int $clientId): ?string
    {
        $this->ci->load->model('bachs/bachs_customers_model');
        $mapping = $this->ci->bachs_customers_model->get_for_client($clientId, $this->is_test_mode() ? 'test' : 'live');

        if (!$mapping) {
            return null;
        }

        try {
            $session = $this->make_client()->createPortalSession($mapping->bachs_customer_id);
        } catch (\Throwable $e) {
            log_activity('Bachs portal session creation failed for client ' . $clientId . ': ' . $e->getMessage());
            return null;
        }

        return $session['url'] ?? $session['portal_url'] ?? null;
    }
}

/**
 * Shows the exact webhook URL to register in Bachs's dashboard, for both
 * environments -- sandbox and production webhook endpoints are entirely
 * separate (confirmed against docs.bachs.io/integrate/sandbox: "Webhook
 * endpoints and event history" are listed explicitly as not shared between
 * environments), so both need registering, each in its own developer
 * portal (switch via the organization switcher, per docs.bachs.io's own
 * instructions).
 */
function bachs_gateway_notice($gateway)
{
    if ($gateway['id'] !== 'bachs') {
        return;
    }

    $webhookUrl = site_url('bachs/bachs_webhook/receive');

    echo '<div class="panel panel-info">';
    echo '  <div class="panel-heading"><h4 class="panel-title"><i class="fa fa-link"></i> Bachs Webhook URL</h4></div>';
    echo '  <div class="panel-body">';
    echo '    <p class="text-muted">Register this exact URL as a webhook destination in <strong>both</strong> your Bachs sandbox and production developer portals separately (switch environments via the organization switcher at the top left of the Bachs dashboard) -- sandbox and production webhook endpoints and signing secrets are completely independent.</p>';
    echo '    <p class="text-muted"><strong>When generating each API key</strong>, grant it permission to manage <strong>products</strong> (create and list) in addition to checkouts/payments -- this module creates and reuses its own custom-amount product per currency automatically, and a key scoped to payments only will fail that step.</p>';
    echo '    <div class="form-group m-b-0">';
    echo '      <div class="input-group">';
    echo '        <input type="text" class="form-control" value="' . $webhookUrl . '" readonly>';
    echo '        <span class="input-group-btn">';
    echo '          <button type="button" class="btn btn-info copy-btn" data-url="' . $webhookUrl . '"><i class="fa fa-copy"></i> Copy</button>';
    echo '        </span>';
    echo '      </div>';
    echo '      <small class="text-muted">After adding the destination in each environment, paste the signing secret Bachs generates into the matching Test/Live Webhook Signing Secret field above.</small>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';

    echo '<script>';
    echo '(function(){';
    echo '  function copyText(text, btn){';
    echo '    try {';
    echo '      var area = document.createElement("textarea");';
    echo '      area.value = text;';
    echo '      document.body.appendChild(area);';
    echo '      area.select();';
    echo '      document.execCommand("copy");';
    echo '      document.body.removeChild(area);';
    echo '      var oldText = btn.innerHTML;';
    echo '      var oldClass = btn.className;';
    echo '      btn.innerHTML = "<i class=\\"fa fa-check\\"></i> Copied!";';
    echo '      btn.className = oldClass.replace("btn-info", "btn-success");';
    echo '      setTimeout(function(){ btn.innerHTML = oldText; btn.className = oldClass; }, 1500);';
    echo '    } catch (e) {';
    echo '      window.prompt("Copy URL:", text);';
    echo '    }';
    echo '  }';
    echo '  document.addEventListener("click", function(e){';
    echo '    var btn = e.target.closest && e.target.closest(".copy-btn");';
    echo '    if (!btn) return;';
    echo '    var url = btn.getAttribute("data-url");';
    echo '    if (url) copyText(url, btn);';
    echo '  });';
    echo '})();';
    echo '</script>';
}
