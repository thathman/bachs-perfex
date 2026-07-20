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

        $currency = strtoupper($data['invoice']->currency_name);
        if ($currency !== 'NGN' && $currency !== 'USD') {
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

        // Reuse an existing open checkout for this invoice rather than
        // creating a new one on every retry (ported from the original TS
        // checkout service).
        $existing = $this->ci->bachs_sessions_model->get_open_for_invoice($data['invoice']->id);
        if ($existing) {
            try {
                $status = $client->getCheckoutStatus($existing->bachs_checkout_id);
                if (strtolower((string) ($status['status'] ?? '')) === 'open' && $existing->checkout_url) {
                    $this->deliver_checkout($existing->checkout_url);
                    return;
                }
            } catch (\Throwable $e) {
                // fall through to creating a fresh session
            }
        }

        $email = null;
        if (is_client_logged_in()) {
            $contact = $this->ci->clients_model->get_contact(get_contact_user_id());
            if (!empty($contact->email)) {
                $email = $contact->email;
            }
        } else {
            $contacts = $this->ci->clients_model->get_contacts($data['invoice']->clientid);
            if (count($contacts) === 1 && !empty($contacts[0]['email'])) {
                $email = $contacts[0]['email'];
            }
        }
        $email = $email ?: 'billing@airixmedia.com';

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
                $email,
                $reference,
                ['invoice_id' => (int) $data['invoiceid'], 'invoice_hash' => (string) $data['invoice']->hash],
                $returnUrl,
                $returnUrl
            );
        } catch (\Throwable $e) {
            log_activity('Bachs checkout creation failed: ' . $e->getMessage());
            set_alert('danger', _l('bachs_checkout_failed'));
            redirect($returnUrl);
            return;
        }

        $this->ci->bachs_sessions_model->create($data['invoice']->id, $session['checkout_id'], $session['checkout_url'] ?? null, (string) ($session['status'] ?? 'created'));

        $this->deliver_checkout($session['checkout_url']);
    }

    /**
     * Hosted and overlay share the exact same checkout_url (confirmed
     * directly against docs.bachs.io/guides/checkout/overlay-checkout) --
     * the only difference is whether the browser is redirected to it
     * server-side, or the same URL is opened client-side in a modal via
     * bachs.js.
     */
    private function deliver_checkout(string $checkoutUrl): void
    {
        if ($this->getSetting('use_overlay_checkout') == '1') {
            $this->ci->load->view('bachs/overlay', ['checkout_url' => $checkoutUrl]);
            return;
        }

        header('Location: ' . $checkoutUrl);
    }

    /**
     * Resolves the correct base URL + API key pair for whichever mode
     * (test/live) is currently enabled, matching the same test/live
     * toggle pattern used by Perfex's other payment gateway modules.
     * Confirmed directly against docs.bachs.io/integrate/sandbox that
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
                'Invoice Payment (' . $currency . ')',
                'Custom-amount checkout for ' . $currency . '-billed invoices.',
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
     * (the live HTTP path) and Bachs_events_model's own cron retry / manual
     * replay path. Throws on any rejection; callers are responsible for
     * mark_processed()/mark_failed().
     */
    public function process_webhook_event(array $envelope): void
    {
        $this->ci->load->model('bachs/bachs_sessions_model');
        $this->ci->load->model('bachs/bachs_transactions_model');
        $this->ci->load->model('invoices_model');
        $this->ci->load->helper('invoices');

        $type = $envelope['type'];

        if ($type !== 'collection.succeeded' && $type !== 'collection.underpaid') {
            $this->ci->bachs_sessions_model->update_status($envelope['data']['checkout_id'] ?? '', 'closed');
            return;
        }

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
        if (($eventCurrency !== 'NGN' && $eventCurrency !== 'USD') || $eventCurrency !== strtoupper($invoice->currency_name)) {
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
