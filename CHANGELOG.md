# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses version tags for its releases.

## [0.2.1] - 2026-10-30
### Changed

- Remove redundant `curl_close()` calls from the cURL transport.

## [0.2.0] - 2026-09-23

### Added

- Add `OdooValue` helpers to consistently decode Odoo field values, including
  empty values, many2one relations, ID lists, strings, numbers, and booleans.
- Add `fromOdoo()` factories to typed model DTOs and use them when hydrating
  records returned by services.
- Add `Domain::condition()`, `Domain::all()`, and `Domain::any()` for composing
  Odoo search domains without mutable builder state.
- Add `PaymentTransaction` DTO with typed payment transaction fields and state
  helpers (`isPending()`, `isDone()`, `isFailed()`, `isCancelled()`).
- Add `PaymentOutcomeUnknownException` for network failures during
  `account.payment.register.action_create_payments`, preserving invoice, wizard,
  communication, and payment-token context for recovery.
- Add Stripe payment-instrument import support for multiple Odoo payment methods,
  including SEPA direct debit and mandate references.
- Add optional `communication` filtering to payment and transaction lookup
  methods for identifying a specific payment attempt.
- Add domain contract-test coverage against a real Odoo 19 instance (opt-in
  through environment variables), plus unit tests for payment recovery,
  Stripe/SEPA handling, domain composition, and value decoding.

### Changed

- Return `PaymentTransaction` DTOs instead of raw rows from
  `PaymentService::findTransactionsForInvoice()`.
- Use the associated payment memo (`payment_id.memo`) when filtering transactions
  by `communication`, so lookup can identify an attempt before reconciliation.
- Expose additional payment-method and Stripe mandate information on payment
  token DTOs.
- Ensure domain combinators produce Odoo-compatible flat prefix expressions,
  including nested AND/OR groups.
- Define an empty `Domain::all()` as TRUE (`[]`) and an empty `Domain::any()` as
  FALSE (`[[0, '=', 1]]`), consistent with Odoo domain semantics.
- Update PHPStan dependencies and documentation.

### Fixed

- Avoid requesting the optional `stripe_mandate` field when verifying a card
  token created on an Odoo schema without that field.
- Validate mandate-bound token imports and verify the persisted Stripe payment
  method and mandate values rather than assuming the import succeeded.
- Avoid blindly retrying payment creation after an ambiguous network failure:
  callers receive `PaymentOutcomeUnknownException` and can inspect Odoo state.

### Migration notes

- Code using `findTransactionsForInvoice()` must access DTO properties rather
  than array keys. Its return type changed from raw rows to
  `PaymentTransaction` objects.
- `PaymentOutcomeUnknownException` extends `OdooException` directly; handle it
  separately from ordinary network failures and reconcile the payment attempt
  before considering another charge.
- The new domain combinators produce flat prefix arrays. An empty
  `Domain::any()` matches nothing, whereas an empty `Domain::all()` matches all.

## [0.1.0] - 2026-09-16

### Added

- Initial release of a PHP 8.3+ client for Odoo 19's External JSON-2 API.
- Generic model operations using JSON-2 named arguments, including searching,
  reading, creating, updating, deleting, grouping, and introspecting fields.
- Typed services and models for customers, invoices, subscriptions, products,
  payments, and API keys, with Stripe, Peppol, and portal PDF functionality.
- Query options and bounded iteration for large result sets, without implicit
  result caps in typed service lists.
- Configurable default Odoo context, with per-call overrides, and per-client
  caching of `fields_get` metadata.
- Injectable HTTP transports, including a cURL fallback and PSR-18 adapters.
- Configurable read-only retries, timeouts, redirect handling, and safeguards
  against automatically replaying writes.
- Distinct exceptions for authorization, network, and Odoo validation errors;
  safe handling of redirected credentials and portal PDF requests.
- Integration examples, documentation, an agent integration skill, PHPUnit
  tests, PHPStan checks, and CI.