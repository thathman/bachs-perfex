# Changelog

All notable changes to this project are documented here.

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
