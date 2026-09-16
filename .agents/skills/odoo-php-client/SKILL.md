---
name: odoo-php-client
description: Guide for integrating with Odoo 19+ External JSON-2 API using the lekoala/odoo-php-client PHP package
license: MIT
compatibility: opencode
metadata:
  audience: developers
  workflow: api-integration
---

## What I do

Provide accurate, version-specific guidance for integrating with Odoo's
External JSON-2 API via the standalone `lekoala/odoo-php-client` Composer
package. I help you:

- Write correct API calls with `Client`, `Odoo` and the typed services
- Configure retries, timeouts and transports
- Understand Odoo model structures and field types
- Avoid common pitfalls when working with Odoo data
- Handle authentication, domains, relations, and errors correctly

## When to use me

Use this skill when:

- Writing or debugging Odoo API integration code with this package
- Choosing between `call()` and the `execute()` compatibility layer
- Tuning retries, timeouts or the HTTP transport
- Understanding why an API call is failing
- Adding new Odoo model interactions

Ask clarifying questions if:

- The Odoo version is unclear (this package supports the v19+ JSON-2 API only)
- You need to determine which service (Customer, Subscription, Invoice, etc.) to use
- You're unsure if a field exists in the Odoo schema

> Project-specific procedures (data migrations, local caches, background
> tasks, business rules of a concrete application) do not belong here; they
> live in the consuming project's own skill.

**Note**: This skill documents the External JSON-2 API used by the standalone
`lekoala/odoo-php-client` package. We target **Odoo 19+** specifically.

## External JSON-2 API

The External JSON-2 API is a REST-style API introduced in Odoo 19.0. Unlike
traditional JSON-RPC, it uses direct HTTP endpoints and Bearer token
authentication.

### Connection & Authentication

**Configuration:**

```php
use LeKoala\OdooClient\Config;
use LeKoala\OdooClient\Odoo;

$config = new Config(
    url: 'https://your-odoo-instance.com',  // Base URL only
    db: 'database_name',
    apiKey: 'your_api_key_here',            // From User Profile → Account Security
);

$odoo = new Odoo($config);
```

**API Key Setup:**

1. Log in to Odoo as the user
2. Go to User Profile (Preferences/My Profile)
3. Open "Account Security" tab
4. Click "New API Key"
5. Set a descriptive name, copy the generated key
6. Store carefully - Odoo won't show it again

**Base URL:** `https://instance.odoo.com/json/2`

**Authentication Headers:**

```text
Authorization: Bearer {api_key}
X-Odoo-Database: {database_name}
Content-Type: application/json
```

Note: No `authenticate()` call needed. Each request includes the Bearer token.

### Request Format

**Endpoint Pattern:**

```text
POST /json/2/{model}/{method}
```

**Request Body (by method):**

| Method | Body Structure |
|--------|----------------|
| `search` | `{"domain": [["field", "=", value]], "limit": 10, "offset": 0, "order": "name asc"}` |
| `search_read` | `{"domain": [["field", "=", value]], "fields": ["name", "email"], "limit": 10}` |
| `read` | `{"ids": [1, 2, 3], "fields": ["name", "email"]}` |
| `create` | `{"vals_list": [{"name": "Test", "email": "test@example.com"}]}` |
| `write` | `{"ids": [1, 2], "vals": {"name": "Updated"}}` |
| `unlink` | `{"ids": [1, 2, 3]}` |
| `fields_get` | `{"attributes": ["string", "help", "type"]}` |

**Example Request:**

```http
POST /json/2/res.partner/search HTTP/1.1
Host: mycompany.odoo.com
Authorization: Bearer a1b2c3d4e5f6...
X-Odoo-Database: mycompany
Content-Type: application/json

{
  "domain": [["is_company", "=", true], ["email", "!=", false]],
  "limit": 10,
  "offset": 0
}
```

### Response Format

**Success Response:**

```json
// search → [7, 18, 12, 14, 17]
// search_read/read → [{"id": 7, "name": "Agrolait"}, ...]
// create → [42] (array of created IDs)
// write/unlink → true
// fields_get → {"name": {"type": "char", "string": "Name"}, ...}
```

**Error Response:**

```json
{
  "name": "odoo.exceptions.ValidationError",
  "message": "Invalid field 'foo' on 'res.partner'"
}
```

### Domain Syntax

Domains are arrays of conditions with Polish notation for operators:

**Basic condition:**

```json
[["email", "=", "test@example.com"]]
```

**Multiple conditions (implicit AND):**

```json
[
  ["is_company", "=", true],
  ["email", "!=", false]
]
```

**OR operator (prefix):**

```json
[
  "|",
  ["email", "=", "test1@example.com"],
  ["email", "=", "test2@example.com"]
]
```

**Complex grouping:**

```json
[
  "&",
  ["is_company", "=", true],
  "|",
  ["email", "ilike", "@gmail.com"],
  ["email", "ilike", "@yahoo.com"]
]
```

**Operators:**

| Operator | Meaning | Example |
|----------|---------|---------|
| `=` | Equals | `["email", "=", "test@example.com"]` |
| `!=` | Not equals | `["email", "!=", false]` |
| `>` `<` `>=` `<=` | Comparison | `["id", ">", 100]` |
| `in` | In list | `["id", "in", [1, 2, 3]]` |
| `not in` | Not in list | `["id", "not in", [1, 2, 3]]` |
| `like` | Pattern (%) | `["name", "like", "John%"]` |
| `ilike` | Case-insensitive | `["email", "ilike", "@GMAIL.COM"]` |
| `child_of` | Hierarchical | `["parent_id", "child_of", 1]` |

A fluent builder is available in the package:

```php
use LeKoala\OdooClient\Domain;

$domain = Domain::build()
    ->where('is_company', '=', true)
    ->where('email', '!=', false)
    ->get();
```

### Pagination

```json
{
  "domain": [["is_company", "=", true]],
  "limit": 20,
  "offset": 0,
  "order": "name asc, id desc"
}
```

Page 2: `offset: 20`, Page 3: `offset: 40`, etc.

### Working with Relations

**Many2one fields:** Accept ID or `[id, "Display Name"]`:

```json
{
  "partner_id": 42
}
```

**One2many / Many2many:** Use command format:

| Command | Format | Purpose |
|---------|--------|---------|
| Create | `[0, 0, {values}]` | Create new related record |
| Update | `[1, id, {values}]` | Update existing |
| Delete | `[2, id, 0]` | Remove and delete |
| Unlink | `[3, id, 0]` | Remove (don't delete) |
| Link | `[4, id, 0]` | Add existing |
| Clear | `[5, 0, 0]` | Remove all |
| Replace | `[6, 0, [ids]]` | Replace with these IDs |

**Example (order lines):**

```json
{
  "vals_list": [{
    "partner_id": 42,
    "order_line": [
      [0, 0, {"product_id": 1, "product_uom_qty": 2, "price_unit": 100}],
      [0, 0, {"product_id": 2, "product_uom_qty": 1, "price_unit": 50}]
    ]
  }]
}
```

## Calling the API: `call()` and `execute()`

`Client::call()` is the primitive to use: `$params` **is** the JSON-2 body,
sent verbatim. Nothing is inferred, reordered, or invented.

```php
$client = $odoo->getClient();

// Recordset methods take their records as the named `ids` parameter.
$client->call('sale.order', 'action_confirm', ['ids' => [123]]);

$id = $client->create('res.partner', ['name' => 'Alice']);

$ids = $client->createMany('res.partner', [
    ['name' => 'Alice'],
    ['name' => 'Bob'],
]);
```

`Client::execute($model, $method, $args, $kwargs)` is a **kept but
deprecated** compatibility layer for legacy RPC-shaped calls: it translates
positional arguments into named parameters by guesswork
(`LegacyRpcArgumentMapper`) and drops anything it cannot name. It stays
available so old call sites keep working, but new code must use `call()`
with explicit named parameters.

Check the instance's `/doc` for the methods actually available on that
database. `action_quotation_sent` is not a confirmation fallback; it only
marks a quotation as sent.

## Retries, timeouts and transports

**Transaction and idempotence boundary:**

- Every JSON-2 call is a separate Odoo transaction. A PHP sequence of
  create, confirm, date update, and token attachment can stop in a partial
  remote state.
- Only transient network failures on **read-only** methods (`search`,
  `search_read`, `read`, `read_group`, `fields_get`, …) are retried.
  `create`, `write`, `unlink` and `action_*` are **never retried
  automatically**.
- Never retry a write or action blindly after a timeout. Search/reconcile
  the remote record first and resume from its observed state.

**Tuning on `Config`:**

```php
$config = (new Config($url, $db, $apiKey))
    ->setMaxRetries(2)              // retries after the first attempt (default 2)
    ->setRetryBackoff(500, 2.0)     // base ms + multiplier (default 500ms × 2^n)
    ->setConnectTimeout(5)          // seconds (default 5)
    ->setTimeout(30)                // seconds (default 30)
    ->setMaxRedirects(5);           // default 5
```

All of these are also accepted by `Config::fromArray()` under the same
names, and preserved by `Config::withApiKey()`.

**Default context.** Set it once on `Config` instead of repeating it per call.
Explicit per-call context wins key by key:

```php
$config->setDefaultContext(['lang' => 'fr_BE', 'allowed_company_ids' => [2]]);
```

`Config::withApiKey()` deliberately drops the API key provider (so the explicit
key is used) but keeps the default context.

**HTTP transport:**

With `ext-curl` installed, no configuration is needed. To reuse Guzzle,
Symfony HttpClient, or another PSR-18 client, inject `Psr18Transport`:

```php
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use LeKoala\OdooClient\Http\Psr18Transport;

$guzzle = new GuzzleClient(['connect_timeout' => 5, 'timeout' => 30]);
$factory = new HttpFactory();

$config->setHttpTransport(new Psr18Transport($guzzle, $factory, $factory));
```

Redirects are handled by the package: sensitive headers are stripped when a
redirect changes origin, and portal PDF requests never carry the API bearer
token.

## Client, services and addons

### Architecture

```text
odoo-php-client/
├── Client.php              # Main client (uses JsonAdapter)
├── Odoo.php                # Facade aggregating all services
├── Config.php              # Credentials, retries, timeouts, transport
├── Adapters/
│   └── JsonAdapter.php     # External JSON-2 API adapter (Odoo 19+)
├── Services/               # Core: customers, subscriptions, invoices,
│                           # products, payments, API keys
├── Addon/
│   ├── Stripe/StripeService.php # Stripe providers and tokens
│   └── Peppol/PeppolService.php # PEPPOL customers and invoices
├── Models/                 # Response DTOs
└── Inputs/                 # Request DTOs (CustomerInput, InvoiceInput, …)
```

### Services usage

Service methods take typed `*Input` DTOs for writes and return typed
`Models` (or plain `int` IDs) — never raw arrays for writes:

```php
use LeKoala\OdooClient\Inputs\CustomerInput;
use LeKoala\OdooClient\Inputs\SubscriptionInput;
use LeKoala\OdooClient\QueryOptions;

// Customers
$customers = $odoo->customers->list();
$customer = $odoo->customers->get(42);
$newId = $odoo->customers->create(new CustomerInput(name: 'John', email: 'john@example.com'));

// Subscriptions (sale.order) — order lines with a recurring product are required
$subId = $odoo->subscriptions->create(new SubscriptionInput(
    partnerId: $customerId,
    planId: $planId,
    orderLines: [[0, 0, ['product_id' => $recurringProductId, 'product_uom_qty' => 1, 'price_unit' => 100.00]]],
));

// Invoices
$invoices = $odoo->invoices->list();
$odoo->invoices->confirm($invoiceId);

// Stripe addon — provider resolution is explicit (company, test/live, ambiguity)
$providerId = $odoo->stripe->requireStripeProviderId();
$tokenId = $odoo->stripe->importStripeToken($partnerId, 'cus_...', 'pm_...', '.... 4242', $providerId);

// PEPPOL addon
$ready = $odoo->peppol->listReadyInvoices();
$odoo->peppol->sendInvoice($invoiceId);

// Invoice PDF (portal access_token, no login required)
$pdfContent = $odoo->invoices->downloadPdf($invoiceId);

// Direct model access for anything not covered by services
$odoo->getClient()->searchRead(
    'res.partner',
    [['is_company', '=', true]],
    ['id', 'name', 'email'],
    limit: 20,
    order: 'name asc',
);
```

### Safe batch querying

Typed service lists apply no hidden cap. Use `QueryOptions` for an explicit
page and `iterate()` for large datasets so memory and request sizes stay
bounded:

```php
use LeKoala\OdooClient\QueryOptions;

$page = $odoo->customers->list([], new QueryOptions(limit: 50, offset: 100, order: 'name asc'));

foreach ($odoo->customers->iterate(options: new QueryOptions(batchSize: 200, order: 'name asc')) as $customer) {
    // Iterators fetch bounded pages; the effective order is stabilized with `id`.
}
```

A null `QueryOptions::$limit` (the default) means no overall cap;
array-returning `list()` methods load all matching rows, so prefer
`iterate()` for large tables. Passing an integer remains supported as
shorthand for an explicit limit.

### fields_get cache

`Client::fieldsOf()` caches `fields_get` per instance (keyed by model +
context). Invalidate explicitly — e.g. after a module upgrade that adds
fields:

```php
$client->clearFieldsCache();              // everything
$client->clearFieldsCache('res.partner'); // one model, every context
```

`getSafeFields()` filters requested fields against that cache so reads never
ask for a field the model does not expose.

### Events

Services dispatch PSR-14 events (`OdooRecordCreatedEvent`,
`OdooRecordUpdatedEvent`, `OdooRecordDeletedEvent`, `PaymentRegisteredEvent`,
`InvoiceCreatedEvent`, …) **after** the remote mutation has committed. A
listener exception is logged and swallowed: it never means the Odoo call
failed and never rolls the remote change back.

## Odoo 19+ field notes

Always verify field names with `fields_get()` (or `getSafeFields()`) before
using them — they change between versions and customizations. That said, the
typical mistakes below deserve to be memorized:

- `mobile` does **not** exist on `res.partner` in Odoo 19. Always use `phone`.
- Subscriptions (`sale.order`) require `plan_id` (label "Recurring Plan")
  and `is_subscription = true`. `recurring_plan_id` and `template_id` do
  not exist / are legacy — don't use them.
- `state` on `sale.order` is the *sale order* status only:
  `draft`/`sent`/`sale`/`cancel` (no `done`, no `close`). Lifecycle lives in
  `subscription_state`, whose values carry their numeric prefix
  (`1_draft`, `2_renewal`, `3_progress`, `4_paused`, `5_renewed`,
  `6_churn`, `7_upsell`).
- Subscription creation requires order lines with a recurring product, or
  Odoo answers "Please add a recurring product".
- Workflow calls take the recordset as the named `ids` body parameter —
  never positional arguments (see `call()` vs `execute()` above).

## Services reference

- **CustomerService** (`res.partner`): `list()`, `iterate()`, `get()`,
  `create()`, `update()`, `search()`, `findByName()`, `findByEmail()`,
  `findByVat()`, `getPaymentTokens()`, `iteratePaymentTokens()`.
- **SubscriptionService** (`sale.order`): `list()`, `iterate()`, `get()`,
  `create()`, `update()`, `updateFields()`, `cancel()`, `getByPartner()`,
  `iterateByPartner()`, `listPlans()`, `iteratePlans()`, `getPortalUrl()`.
  `cancel()` calls `action_cancel` (never writes `state` directly) and
  re-reads the record to require `state = cancel` unless `verifyState: false`.
- **InvoiceService** (`account.move`): `list()`, `iterate()`, `get()`,
  `create()`, `createCreditNote()`, `confirm()`, `getOutstanding()`,
  `listUnpaid()`, `downloadPdf()`, `getPortalUrl()`.
- **ProductService** (`product.product`): `list()`, `iterate()`, `get()`,
  `search()`, `getByCategory()`, `listTaxes()`, `iterateTaxes()`.
- **PaymentService** (`account.payment.register` wizard): `registerPayment()`,
  `processPaymentToken()`, `getJournals()`, `getPaymentMethods()`.
- **ApiKeyService** (`res.users.apikeys`): `listKeys()`, `findByName()`.
- **Addon\Stripe\StripeService**: `requireStripeProviderId()`,
  `importStripeToken()`, `findStripeToken()`, `getCardPaymentMethodId()`.
- **Addon\Peppol\PeppolService**: `listEnabledCustomers()`,
  `listReadyInvoices()`, `listInvoicesByStatus()`, `sendInvoice()`.

## Exception handling

```php
use LeKoala\OdooClient\Exceptions\OdooAuthException;
use LeKoala\OdooClient\Exceptions\OdooNetworkException;
use LeKoala\OdooClient\Exceptions\OdooValidationException;

try {
    $result = $odoo->customers->create(new CustomerInput(name: 'Test'));
} catch (OdooAuthException $e) {
    // Invalid API key (HTTP 401). Check User Profile → Account Security.
} catch (OdooNetworkException $e) {
    // Timeout or transient gateway error (HTTP 502/503/504).
    // Read-only calls were already retried per Config; writes were not —
    // reconcile the remote state before replaying.
} catch (OdooValidationException $e) {
    // Odoo refused the call: invalid field, validation rule, missing record.
    // $e->getOdooName() / getOdooMessage() carry the Odoo envelope.
}
```

Common errors:

- **Invalid field** (`odoo.exceptions.ValidationError`, "Invalid field 'foo'")
  → check available fields with `fields_get()` / `getSafeFields()`.
- **Access denied** (`odoo.exceptions.AccessError`, HTTP 403) → the key is
  valid but its user has no rights on the model or record.
- **Record not found** (`odoo.exceptions.MissingError`) → verify the ID first.

## PDF downloads (InvoiceService)

Posted invoices expose a portal URL (`access_token` + `access_url`) that
serves the PDF without login:

```php
$pdfContent = $odoo->invoices->downloadPdf($invoiceId);
// GET {baseUrl}/my/invoices/{id}?access_token={token}&report_type=pdf&download=true

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="invoice.pdf"');
echo $pdfContent;
```

Troubleshooting: "no portal access token" means the invoice is still `draft`
or the portal fields were not read; an HTML response instead of a PDF means
the token is expired or invalid.

## Debugging

```php
$version = $odoo->getClient()->version();
// ['server_version' => '19.0', ...] — answered from /web/version.

$fields = $odoo->getClient()->call('res.partner', 'fields_get', [
    'attributes' => ['string', 'help', 'type', 'required'],
]);
```

## External Resources

- **Official JSON-2 API Docs**: https://www.odoo.com/documentation/19.0/developer/reference/external_api.html#external-json-2-api
