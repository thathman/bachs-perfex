# Changelog

All notable changes to this project are documented here.

## 1.3.0 - 2026-08-26

### Added

- **Refunds.** Staff can issue full or partial refunds against a Bachs
  transaction directly from the admin transactions screen. Refund status
  (pending/succeeded/failed) is tracked in its own table and kept in sync
  via webhook (`refund.succeeded` / `refund.failed`), never assumed from the
  initial API response alone.
- **Disputes.** Incoming Bachs dispute webhooks (`dispute.created`,
  `dispute.updated`, `dispute.closed`) are recorded and surfaced to staff,
  with the transaction they apply to cross-referenced automatically.
- **Customers.** A `Bachs_customers_model` maps Perfex clients to their
  Bachs customer IDs, created lazily on first checkout/subscription rather
  than provisioned up front, so a client who never pays never gets a
  Bachs-side record.
- **Subscriptions**, forked alongside (not replacing) Perfex's native
  Subscriptions feature: a Bachs-backed recurring billing flow with its own
  admin screen (`bachs_subscriptions_admin`), client-facing preview and
  management page, and a full subscription lifecycle synced from Bachs
  webhooks (`customer.subscription.created/updated/deleted`,
  trialing/active/past_due/unpaid/paused/canceled). Menu item is gated on
  the same `subscriptions` permission Perfex's native feature already uses,
  so no separate permission has to be granted.
- A shared subscription-status pill (`bachs_subscription_status_label()`)
  so a subscription's status renders identically in the staff admin view
  and the client-facing preview.

### Fixed

- **Every incoming webhook was fatal-erroring.** A prior change pointed
  `Bachs_webhook::receive()` and the module's cron retry path at
  `integration_runtime/integration_events_model` -- a separate shared
  module that was never actually present alongside `bachs` on the server
  it shipped to. Every webhook (payment confirmation, refund, dispute,
  subscription update) hit this line and fatal-errored before the event
  was ever processed. Reverted to this module's own self-contained
  `Bachs_events_model` / `tblbachs_events` (the exact architecture 1.1.0
  documented merging in, and which `install.php` had silently stopped
  creating somewhere along the way -- restored here too, so a fresh install
  doesn't hit the same gap). The fix was verified end-to-end against a real
  Bachs sandbox subscription (checkout creation with a genuinely recurring
  product, followed by a correctly-signed `customer.subscription.created`
  webhook) before being considered done, catching a second, self-inflicted
  bug in the same fix: `$this->load->model('bachs/Bachs_events_model')`
  (capital B) registers the loaded model as `$this->Bachs_events_model`,
  not the lowercase `$this->bachs_events_model` every call site actually
  reads -- corrected to load with an all-lowercase path, matching every
  other model load in this module.
- **Overlay Checkout re-triggering a brand-new charge on an already-paid
  invoice.** Closing or cancelling the overlay called `window.location.reload()`
  on a page that was itself loaded as the response to the invoice's "Pay Now"
  POST -- reloading it resubmitted that POST, silently opening a second
  checkout session on an invoice that had already been paid. Fixed by
  navigating to the invoice URL with a real GET request instead of reloading.

## 1.2.0 - 2026-07-20

### Fixed

- **Overlay Checkout stuck permanently on "Loading secure checkout..." in
  Test Mode.** `bachs.js` validates that the checkout URL passed to
  `Bachs.Checkout.open()` lives on the same origin as the `baseUrl` given
  to `Bachs.Initialize()`, and silently does nothing (emitting an unlistened
  `checkout.error` event) if it doesn't match -- confirmed directly by
  reading the real `bachs.js` source. The overlay view never passed
  `baseUrl` at all, so it defaulted to the live checkout origin
  (`checkout.bachs.io`); since a sandbox checkout's real URL is on a
  different origin (`sandbox-checkout.bachs.io`), overlay mode only ever
  worked in Live mode and hung silently in Test Mode. Fixed by passing the
  correct checkout origin for whichever mode is active, and by listening
  for `checkout.error` so any future failure is surfaced instead of hanging
  silently.

## 1.1.0 - 2026-07-20

### Changed

- **Merged into a single self-contained module.** The separate
  `integration_runtime` module (shared webhook idempotency/retry/dead-letter
  infrastructure) has been folded directly into `bachs` as
  `Bachs_events_model` and a `tblbachs_events` table. Installation is now
  one folder, one activation -- no dependency-ordering step, no second
  module to keep track of.
- Removed all references to other Perfex payment gateway modules by name
  from the documentation and code comments.
- Product names created by automatic provisioning are now generic
  ("Invoice Payment (NGN)") rather than tied to any specific business name.

### Fixed

Three real bugs found via live testing against a real Perfex install and a
real Bachs sandbox account, all now fixed:

- **Checkout creation permanently failing after the first retry.** Bachs
  enforces reference uniqueness for the lifetime of an organization, not
  just while a checkout session is open. The module previously reused the
  invoice's bare ID as the checkout reference, so any retry after the first
  session expired or was abandoned was rejected outright with "Reference
  already exists for this organization" -- permanently, since the same
  reference would always collide. Fixed by generating a fresh, unique
  reference on every checkout attempt, and resolving the invoice from the
  webhook event's `metadata.invoice_id` field instead of parsing the
  reference as the invoice ID.
- **A blank white screen when Overlay Checkout was enabled.** The overlay
  view incorrectly used Perfex's admin-panel-only `init_head()`/`init_tail()`
  helpers, which call `get_staff_started_timers()` and load the admin
  sidebar/theme -- both of which require a staff session that does not
  exist during a client-facing checkout. Fixed by rewriting the overlay view
  as a fully self-contained HTML page with no Perfex theme dependency.
- **A confirmed, successful payment never marking its invoice as paid.**
  The webhook handler's own amount-sanity check compared a card payment's
  gross, fee-inclusive charged amount against the invoice's remaining
  balance, which is always higher than the net amount actually owed
  whenever Bachs passes a processing fee through to the customer -- causing
  every such payment to be rejected as "exceeding the balance" and never
  applied. Fixed by preferring the webhook event's `settlement_amount`
  field (the real net amount) over its `amount` field (the gross charged
  total) when present.

## 1.0.0 - 2026-07-20

Initial public release.

### Added

- `bachs` module: Bachs.io payment gateway for Perfex CRM, following the
  same conventions as Perfex's other payment gateway modules.
  - Hosted checkout (server-side redirect) and overlay checkout (Bachs's
    own `bachs.js` widget), toggled with a single setting.
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
  (Merged into `bachs` directly in 1.1.0; see above.)

### Security

- Fixed a real activation-hook gap: `integration_runtime`'s main module
  file never called `register_activation_hook()`, meaning a fresh install
  would activate successfully without ever creating its own database
  table.
- Refactored Bachs webhook processing so the live HTTP path and the cron
  retry / manual-replay path share one implementation
  (`Bachs_gateway::process_webhook_event()`), instead of duplicating the
  logic in two places that could silently drift apart.
