# Bachs.io for Perfex CRM

A native Bachs.io payment gateway module for [Perfex CRM](https://www.perfexcrm.com/). One self-contained module: drop in one folder, activate it, configure it. No separate shared-infrastructure module to install first.

<p align="center">
  <a href="https://github.com/sponsors/thathman">
    <img src="https://img.shields.io/badge/Sponsor-GitHub%20Sponsors-EA4AAA?style=for-the-badge&logo=githubsponsors&logoColor=white" alt="Sponsor this project on GitHub Sponsors">
  </a>
</p>

If this module saves you time, consider [sponsoring the project on GitHub](https://github.com/sponsors/thathman). It funds the maintenance and testing this kind of payment code needs.

## Contents

- [Why this exists](#why-this-exists)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [How automatic product provisioning works](#how-automatic-product-provisioning-works)
- [Hosted checkout vs. overlay checkout](#hosted-checkout-vs-overlay-checkout)
- [Webhooks](#webhooks)
- [Currencies](#currencies)
- [Architecture](#architecture)
- [Security](#security)
- [Troubleshooting](#troubleshooting)
- [Uninstalling](#uninstalling)
- [Contributing](#contributing)
- [License](#license)
- [Credits](#credits)

## Why this exists

Bachs.io has no official Perfex CRM integration. This module adds one that behaves exactly like a stock Perfex payment gateway: it appears in Settings, Payments like any other method, and a client paying an invoice sees the same familiar flow as any other online payment option.

The module treats sandbox and live as genuinely separate environments, because that is how Bachs itself treats them: separate base URLs, separate API keys, separate webhook signing secrets, and separate checkout products. Nothing about the module quietly assumes the two environments share anything, since they do not.

## Features

- One module, one folder. Webhook idempotency, retry, and dead-letter handling are built directly into this module, not split into a second dependency you have to install and activate in the right order.
- Full sandbox/live separation: distinct API keys and webhook secrets per environment, switched with one Test Mode toggle.
- Automatic checkout product provisioning. No product ID to create or paste in by hand; see [How automatic product provisioning works](#how-automatic-product-provisioning-works).
- NGN and USD support out of the box, each invoice automatically routed to a product in its own currency.
- Hosted checkout (redirect to Bachs) and overlay checkout (Bachs's own JavaScript widget, invoked from your invoice page), toggled with a single setting.
- Webhook-only payment confirmation. A client's browser landing back on your site after paying is never treated as proof of payment; only a signature-verified webhook event ever records money against an invoice.
- Amount and currency sanity checks on every webhook event, comparing against the invoice's actual net amount owed (not a card-processing-fee-inclusive total), so a malformed, unexpected, or misread event cannot silently corrupt an invoice balance.
- Webhook events that fail for a transient reason (a brief network blip, a momentary database error) retry automatically with exponential backoff, and a staff-only screen shows anything still failed or dead-lettered with a one-click manual replay.
- A staff-only transactions screen showing every confirmed Bachs charge.

## Requirements

- Perfex CRM 3.3.x or later. Built and tested against 3.4.1.
- PHP 8.0 or later, with the `curl` and `openssl` extensions (both are standard Perfex requirements already).
- A Bachs.io account, with separate API keys for sandbox and (when you are ready) live.

## Installation

1. Download or clone this repository.
2. Copy `modules/bachs` into your Perfex installation's `modules/` directory, so you end up with `modules/bachs/`.
3. Log in to Perfex as a staff member with administrator access and go to **Setup, Modules**.
4. Activate **Bachs.io**.
5. Go to **Setup, Settings, Sales, Payment Gateways** and open the **Bachs.io** tab to configure it (see [Configuration](#configuration) below).

If you are packaging this from a ZIP archive rather than cloning the repository, extract it and copy the one folder from inside `modules/` the same way.

## Configuration

Open **Setup, Settings, Sales, Payment Gateways, Bachs.io**. The settings are grouped by environment:

**Test Mode** - a yes/no toggle. While enabled, the module uses your sandbox API key, sandbox webhook secret, and sandbox checkout products. Turn this off only when you are ready to accept real payments.

**Sandbox Environment**

- **Sandbox API Key** - your `sk_sandbox_...` key from the Bachs sandbox dashboard.
- **Sandbox Webhook Signing Secret** - the signing secret Bachs gives you when you register a webhook destination in the sandbox dashboard (see [Webhooks](#webhooks)).

**Live Environment**

- **Live API Key** - your `sk_live_...` key from the Bachs live dashboard.
- **Live Webhook Signing Secret** - the signing secret from a webhook destination registered in the live dashboard.

**Use Overlay Checkout** - see [Hosted checkout vs. overlay checkout](#hosted-checkout-vs-overlay-checkout).

**Currencies (`settings_paymentmethod_currencies`)** - a comma-separated list (for example `NGN,USD`) controlling which invoice currencies show Bachs as a payment option at all. This is a stock Perfex setting shared by every gateway module; Perfex checks the invoice's currency against this list before it will even display the payment button. Remove a currency here if you do not want Bachs offered for it, regardless of whether a checkout product exists for it.

There is deliberately no field for the API base URL. Bachs uses exactly two fixed endpoints (`api.bachs.io` for live, `sandbox-api.bachs.io` for sandbox); the module hardcodes both rather than exposing a setting that could never legitimately change.

There is also no field for a product ID. See the next section.

## How automatic product provisioning works

Bachs requires every checkout to reference a product, and a product's price is fixed unless it is created with `price_type: custom`, which allows the checkout request to override the amount per invoice. Rather than asking you to create this product yourself and paste an ID into a settings field, the module does it for you:

1. On the first checkout in a given environment and currency, the module asks Bachs for your existing products.
2. If an active, custom-priced product already exists for that currency, the module reuses it.
3. If none exists, the module creates one (named, for example, "Invoice Payment (NGN)") via the Bachs API.
4. The resulting product ID is cached internally and reused for every subsequent checkout in that environment and currency. It is never shown on the settings page, because there is nothing for you to configure.

This means your Bachs API key needs permission to manage products (create and list), not only payments. When you generate an API key in the Bachs dashboard, grant it that scope; a key scoped to payments only will fail the automatic-provisioning step. The module's settings page includes a reminder about this next to the webhook URL.

## Hosted checkout vs. overlay checkout

Bachs offers two ways to present the same checkout session, and this module supports both from one toggle:

- **Hosted** (the default): the client's browser is redirected to a checkout page hosted by Bachs.
- **Overlay**: the invoice page loads Bachs's own `bachs.js` widget script, which opens the checkout in a real iframe modal on top of your own page, without leaving your site.

`bachs.js` validates that the checkout URL it is asked to open lives on the same origin as the `baseUrl` passed to `Bachs.Initialize()`. Sandbox and live checkouts are served from different origins (`sandbox-checkout.bachs.io` vs. `checkout.bachs.io`), so this module passes the matching one for whichever mode is currently active -- get this wrong and the widget silently refuses to open the modal at all, with no visible error.

Both modes use the exact same underlying `checkout_url`; only the presentation differs.

## Webhooks

The module exposes one webhook endpoint, shared by both environments:

```
https://your-perfex-domain.example/bachs/bachs_webhook/receive
```

Because Perfex applies CSRF protection to POST requests by default, and a webhook cannot carry a CSRF token, add this exact path to `csrf_exclude_uris` in `application/config/config.php`:

```php
$config['csrf_exclude_uris'] = [
    // ...whatever is already here...
    'bachs/bachs_webhook/receive',
];
```

Register this URL as a webhook destination separately in both your Bachs sandbox dashboard and your Bachs live dashboard (switch environments using the organization switcher in the Bachs dashboard). Each registration gives you a different signing secret; paste the sandbox one into **Sandbox Webhook Signing Secret** and the live one into **Live Webhook Signing Secret**.

Webhook signatures are verified using HMAC-SHA256 over `{unix_timestamp}.{raw_request_body}`, matching the `X-Bachs-Timestamp` and `X-Bachs-Signature` headers Bachs sends, with a 300-second tolerance window against replay. A request with a missing, wrong, or stale signature is rejected before its body is ever parsed.

The invoice a webhook event applies to is resolved from the event's own `metadata.invoice_id` field, not from the checkout's `reference`. Bachs enforces reference uniqueness for the lifetime of your organization, not just while a checkout session is open, so this module generates a fresh, unique reference on every checkout attempt (a retried/abandoned-then-recreated checkout would otherwise be rejected by Bachs as a duplicate reference) and relies on metadata for the actual invoice link.

The amount applied to an invoice is taken from the event's `settlement_amount` field when present, not its `amount` field. For card payments where Bachs passes a processing fee through to the customer, `amount` is the gross, fee-inclusive total actually charged, while `settlement_amount` is the net amount that settles against the invoice -- using the gross figure would make every fee-inclusive card payment look like it "overpaid" the invoice and get rejected by the amount sanity check below.

## Currencies

The module ships configured for NGN and USD. Adding a third currency means:

1. Adding it to the `currencies` setting so Perfex will display the gateway on invoices in that currency.
2. Adding the currency to the accepted list in `Bachs_gateway::process_payment()` and `Bachs_gateway::process_webhook_event()` (both currently check for exactly `NGN` and `USD`).

Bachs always settles payouts in NGN or USD regardless of what a customer pays in, so if you plan to accept a third invoice currency, confirm with Bachs how that gets converted before relying on it.

## Architecture

```
modules/bachs/
  bachs.php                        Module bootstrap: registers the gateway,
                                    the activation hook, and the cron retry
                                    sweep (hooked to Perfex's own
                                    after_cron_run).
  libraries/
    Bachs_gateway.php               extends App_gateway. Owns settings,
                                    make_client(), automatic product
                                    resolution, and process_webhook_event()
                                    -- the single implementation shared by
                                    both the live webhook path and the
                                    cron retry / manual replay path.
  controllers/
    Bachs_webhook.php               Public webhook receiver: body-size cap,
                                    signature verification, idempotent
                                    recording, then delegates to
                                    Bachs_gateway.
    Bachs_admin.php                 Staff-only screen: confirmed
                                    transactions, plus failed/dead-lettered
                                    webhook events with a manual replay
                                    action.
  models/
    Bachs_events_model.php          Insert-first idempotent event
                                    recording, atomic claim/lock (so a live
                                    webhook and the cron sweep can never
                                    double-process one event), exponential
                                    backoff, dead-letter after 6 attempts.
    Bachs_sessions_model.php        Tracks open checkout sessions per invoice.
    Bachs_transactions_model.php    Tracks confirmed charges, keyed uniquely
                                    by Bachs's own charge ID.
  src/
    BachsClient.php                 Thin HTTP client for the Bachs API.
    BachsAmounts.php                Minor/major unit conversion, string-based
                                    to avoid float rounding errors.
```

## Security

This module handles real money, so a few things are non-negotiable:

- **A browser redirect is never payment confirmation.** Whether checkout is hosted or overlay, the only thing that ever records a payment against an invoice is a webhook request with a valid signature.
- **Signature verification is constant-time** (`hash_equals`), to avoid a timing side-channel on the comparison.
- **Replay protection**: a webhook timestamp more than 300 seconds old or in the future is rejected outright, in addition to the idempotency check on the event ID itself.
- **Amount sanity checks**: an incoming webhook's `metadata.invoice_id` must be a genuine numeric invoice ID, its settlement amount must be a positive number, its currency must match the invoice's own currency, and the amount must not exceed the invoice's actual remaining balance (with a small epsilon for rounding). A signature proves a request came from Bachs; it does not prove the amount inside it is sane relative to what is actually owed, so this module checks that separately.
- **A capped request body size** on the public webhook endpoint, rejected before the body is parsed.
- **Encrypted secrets at rest**: API keys and webhook signing secrets are stored using Perfex's own encryption library, never in plain text.
- **No credentials in this repository.** Every example key or secret you see in this codebase (in comments, in the README) is a format placeholder, never a real value.

See [SECURITY.md](SECURITY.md) for how to report a vulnerability.

## Troubleshooting

**The Bachs.io tab does not show a "Checkout Type" dropdown.** Perfex's own payment-gateway settings view only renders `yes_no`, `input`, and `textarea` field types; a `select` type is silently never displayed at all. This module uses a **Use Overlay Checkout** yes/no toggle instead, for exactly this reason.

**Bachs does not appear as a payment option on an invoice.** Check the `currencies` setting on the Bachs.io settings tab. Perfex only shows a gateway on an invoice if the invoice's currency is in that comma-separated list, regardless of whether the gateway is otherwise fully configured.

**A checkout fails immediately with an error, especially on a retry.** If the underlying error (visible in Perfex's activity log) mentions a reference already existing for your organization, you are very likely running an older version of this module that reused the bare invoice ID as the checkout reference. Update to the current version, which generates a fresh unique reference per checkout attempt.

**A webhook succeeded on Bachs but the invoice was never marked paid, particularly for a card payment.** Check the event's error message on the Bachs.io admin screen. If it says the amount exceeds the invoice's remaining balance, and the actual charge included a processing fee passed through to the customer, you are likely running an older version that compared the gross charged amount instead of `settlement_amount`. Update to the current version, then use the Replay action on the admin screen to reprocess the event correctly -- it will not double-charge or double-record, since the underlying charge ID is already tracked idempotently.

**Overlay Checkout gets stuck on "Loading secure checkout..." and never opens.** `bachs.js` silently refuses to open a checkout URL whose origin doesn't match the `baseUrl` passed to `Bachs.Initialize()`, and does not surface this as a visible error unless the page listens for the `checkout.error` event. An older version of this module never passed `baseUrl` at all, so the widget defaulted to the live checkout origin -- meaning overlay mode only ever worked in Live mode, and got stuck silently in every Test Mode checkout, since the real `checkout_url` in test mode is on a different (`sandbox-checkout.bachs.io`) origin. Update to the current version, which passes the matching origin for whichever mode is active.

**A webhook returns 419 / Page Expired.** The webhook route is missing from `csrf_exclude_uris`; see [Webhooks](#webhooks).

**A webhook returns 404.** Perfex's routing resolves a URI segment to a controller file with `ucfirst()`, so the controller class name must exactly match the folder-plus-class convention used here (`bachs/bachs_webhook/receive`, not `bachs_webhook/receive`). If you have renamed anything, check the resulting URL matches the actual class name.

**Activating the module seems to do nothing.** Perfex's own `App_modules::activate()` only inserts the module's row in `tblmodules`; it does not run `install.php` itself. This module registers an activation hook that does, so activating it through **Setup, Modules** creates its tables automatically. If you have modified the bootstrap file, keep that hook.

## Uninstalling

Deactivating a Perfex module does not drop its tables by default; remove `tblbachs_checkout_sessions`, `tblbachs_transactions`, and `tblbachs_events` manually if you want a clean removal, and back up first if any of them hold real transaction history you want to keep.

## Contributing

Issues and pull requests are welcome. Given this is a payment integration, please:

- Include a clear description of what changed and why.
- Note whether you tested against a real Bachs sandbox account, and what you tested.
- Avoid including any real API keys, webhook secrets, or other credentials in an issue, pull request, or commit, even redacted examples that look plausible enough to confuse with a real one.

## License

MIT. See [LICENSE](LICENSE).

## Credits

Built and maintained by [Hendrix Nwaokolo](https://github.com/thathman).

If this module is useful to you, a [GitHub Sponsors](https://github.com/sponsors/thathman) contribution helps keep it maintained.
