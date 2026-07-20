# Security Policy

This is a payment integration. Treat any suspected vulnerability as urgent.

## Reporting a vulnerability

Do not open a public GitHub issue for a security report. Instead, use GitHub's
private vulnerability reporting for this repository (Security tab -> Report a
vulnerability), or contact the maintainer directly through the GitHub profile
linked in the README.

Please include:

- A description of the issue and its potential impact.
- Steps to reproduce, including a sample webhook payload or request if
  relevant (with any real API keys or secrets redacted).
- The module version and Perfex CRM version you tested against.

You will get an acknowledgement within a few days. Please allow a reasonable
window to fix the issue before any public disclosure.

## What is in scope

- Signature verification and replay protection on the webhook receiver
  (`modules/bachs/controllers/Bachs_webhook.php`).
- Encryption/handling of API keys and webhook secrets.
- Any path where an unauthenticated or under-privileged request could create,
  modify, or read a payment, invoice, or credential.
- SQL injection, stored/reflected XSS, CSRF, IDOR in either module.

## What is explicitly out of scope

- Vulnerabilities in Perfex CRM core itself, or in third-party modules this
  project does not touch. Report those to the Perfex/CodeCanyon vendor.
- Vulnerabilities in Bachs's own API or dashboard. Report those to Bachs
  directly.
- Denial of service against your own server's resource limits (PHP memory,
  MySQL connection limits, etc.) that is not specific to a flaw in this code.

## Supported versions

Only the latest release on the `main` branch is supported with security
fixes. There is no long-term support branch.
