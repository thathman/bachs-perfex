# Changelog

All notable changes to this project are documented here.

## 1.0.0 - 2026-07-20

Initial public release.

### Added

- `bachs` module: Bachs.io payment gateway for Perfex CRM, following the
  stock `paystack`/`flutterwave` gateway module pattern.
  - Hosted checkout (server-side redirect) and overlay checkout (embedded
    modal via `bachs.js`), toggled with a single setting.
  - Separate sandbox and live environments: separate API keys, separate
    webhook signing secrets, separate reusable checkout products, resolved
    automatically from a single Test Mode toggle.
  - Automatic product provisioning: the module creates and reuses its own
    custom-amount Bachs product per currency via the API. No product ID to
    configure by hand.
  - NGN and USD support, each invoice routed to the matching product and
    currency automatically.
  - Webhook receiver with HMAC-SHA256 signature verification
    (`X-Bachs-Timestamp` / `X-Bachs-Signature`), a 300-second replay
    tolerance window, and a hard rule that only a verified webhook -- never
    a browser landing on a success URL -- ever confirms a payment.
  - Amount sanity checks on every incoming webhook: reference must be
    numeric, amount must be positive, currency must match the invoice, and
    the amount must not exceed the invoice's actual remaining balance.
  - A capped request body size on the public webhook endpoint.
  - A staff-only transactions screen under Utilities.
  - A settings-page notice showing the exact webhook URL to register in
    both the Bachs sandbox and live developer portals, plus a reminder to
    grant the API key product-management scope.
- `integration_runtime` module: shared event-idempotency, retry, and
  dead-letter foundation, reusable by any webhook-receiving module.
  - Insert-first idempotent event recording, keyed on
    `(provider, external_event_id)`.
  - Atomic claim/lock so a live webhook request and the cron retry sweep
    can never process the same event twice.
  - Exponential backoff (1/2/4/8/16 minutes) with a dead-letter state after
    6 attempts.
  - A per-provider retry hook (`integration_runtime_process_{provider}`) so
    this module never needs to know how any specific integration processes
    its own events.
  - An admin screen listing failed and dead-lettered events with a
    one-click manual replay.

### Security

- Fixed a real activation-hook gap: `integration_runtime`'s main module
  file never called `register_activation_hook()`, meaning a fresh install
  would activate successfully without ever creating its own database
  table. A prior fix had been applied directly to a single production
  instance but never reflected back into the module file itself; that has
  now been corrected in the source.
- Refactored Bachs webhook processing so the live HTTP path and the cron
  retry / manual-replay path share one implementation
  (`Bachs_gateway::process_webhook_event()`), instead of duplicating the
  logic in two places that could silently drift apart.
- Registered the missing `integration_runtime_process_bachs` retry hook --
  without it, a transiently-failed Bachs webhook event (a network blip, a
  brief database error) would sit in `failed` status forever, since the
  cron sweep fired a hook nobody was listening for.
