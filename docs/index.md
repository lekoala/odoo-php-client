# LeKoala Odoo PHP Client

This library provides a typed PHP interface for interacting with Odoo's API with a focus on Invoicing, Subscriptions, and Product modules via the External JSON-2 API (Odoo 19+).

## Table of Contents

1. [Getting Started](#getting-started)
2. [Architecture](#architecture)
3. [Services](#services)
    - [SubscriptionService](#subscriptionservice)
    - [InvoiceService](#invoiceservice)
    - [ProductService](#productservice)
    - [PaymentService](#paymentservice)
    - [CustomerService](#customerservice)
4. [PEPPOL Electronic Invoicing](#peppol-electronic-invoicing)
5. [Error Handling](#error-handling)
6. [HTTP Transport](#http-transport)
7. [Pagination](#pagination)

## Getting Started

Initialize the `Odoo` facade with your credentials:

```php
use LeKoala\OdooClient\Config;
use LeKoala\OdooClient\Odoo;

$config = new Config(
    url: 'https://your-instance.odoo.com',
    db: 'your-database',
    apiKey: 'your-api-key'
);

$app = new Odoo($config);

// Quickstart Example: Get the 5 most recent customers
$customers = $app->customers->list([], 5);
foreach ($customers as $customer) {
    echo "{$customer->name} (ID: {$customer->id})\n";
}
```

---

## Architecture

The library is built using a **Facade** pattern via `Odoo`, which orchestrates several specialized services.

### Core Components

- **Client**: Handles low-level JSON-2 API communication.
- **Input DTOs**: Structured requirements for creating/updating records. See [Input Documentation](inputs.md).
- **Model DTOs**: Typed records returned from Odoo. See [Model Documentation](models.md).

---

## HTTP Transport

HTTP implementations are injected through `Config::setHttpTransport()`. The default
is `CurlTransport` when `ext-curl` is available. `Psr18Transport` allows an application to
reuse its existing PSR-18 client, request factory, and stream factory without adding a
Guzzle or Symfony dependency to this package.

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use LeKoala\OdooClient\Http\Psr18Transport;

$factory = new HttpFactory();
$config->setHttpTransport(new Psr18Transport(
    new Client(['connect_timeout' => 5, 'timeout' => 30]),
    $factory,
    $factory,
));
```

Transport implementations send one exchange at a time. The package handles bounded
redirects above them so behavior remains identical across cURL, Guzzle, and Symfony.
Credentials are stripped on cross-origin redirects. Portal PDF requests use their portal
token only and never inherit JSON-2 authentication headers.

---

## Pagination

Service list methods accept `QueryOptions` and have no implicit maximum. The object
controls `limit`, `offset`, `order`, `fields`, `context`, and iterator `batchSize`.

```php
use LeKoala\OdooClient\QueryOptions;

$options = new QueryOptions(
    limit: 500,
    offset: 0,
    order: 'write_date desc',
    fields: ['id', 'name', 'write_date'],
    context: ['allowed_company_ids' => [2]],
    batchSize: 100,
);

foreach ($app->invoices->iterate(options: $options) as $invoice) {
    // At most 500 results, fetched 100 at a time.
}
```

Iterators always use a deterministic order. If the requested order does not contain
`id`, `id asc` is appended as a tie-breaker. `iterateByPartner()`, `iteratePlans()`,
`iteratePaymentTokens()`, and `iterateTaxes()` cover the specialized collections.

---

## Services

### SubscriptionService

Manages Odoo subscriptions (`sale.subscription`).

- `list(array $domain, QueryOptions|int|null $options)`: Returns matching `Subscription` models without a hidden cap.
- `iterate(array $domain, ?QueryOptions $options)`: Lazily yields subscriptions in stable pages.
- `iterateByPartner(int $partnerId, ?QueryOptions $options)`: Lazily yields one partner's subscriptions.
- `listPlans()` / `iteratePlans()`: Returns or iterates available recurring plans.
- `listPaymentErrors()`: Returns subscriptions with failed payment transactions.
- `create(SubscriptionInput $input)`: Creates a new subscription.
- `update(int $id, SubscriptionInput $input)`: Updates an existing subscription.
- `cancel(int $id)`: Closes a subscription.

### InvoiceService

Manages Odoo invoices (`account.move`).

- `list(array $domain, QueryOptions|int|null $options)`: Returns matching invoices without a hidden cap.
- `iterate(array $domain, ?QueryOptions $options)`: Lazily yields invoices in stable pages.
- `create(InvoiceInput $input)`: Creates a draft invoice with lines.
- `confirm(int $id)`: Posts (confirms) a draft invoice.
- `getOutstanding(int $partnerId)`: Returns unpaid posted invoices for a partner.
- `listUnpaid()`: Returns all unpaid or partially paid posted invoices globally.
- `listPeppolReady()`, `listByPeppolStatus()`, `sendViaPeppol()`: Deprecated PEPPOL proxies, use `Odoo::$peppol` (`Addon\Peppol\PeppolService`).

### ProductService

Manages the Odoo product catalog (`product.product`).

- `list(array $domain, QueryOptions|int|null $options)`: Returns matching products without a hidden cap.
- `iterate(array $domain, ?QueryOptions $options)`: Lazily yields products in stable pages.
- `listTaxes()` / `iterateTaxes()`: Returns or iterates available taxes.
- `search(string $query)`: Searches products by name or reference.
- `getByCategory(string $name)`: Finds products within a specific category.

### PaymentService

Handles payment registration via the `account.payment.register` wizard.

- `registerPayment(PaymentInput $input)`: Creates and reconciles payments, returning a `PaymentResult` with every payment/transaction ID and an explicit resolution status.
- `getJournals()`: Lists available bank/cash journals.
- `processPaymentToken(int $invoiceId, float $amount, int $tokenId, ?int $journalId = null, ?int $paymentMethodLineId = null)`: Charges an invoice using a saved Odoo payment token and returns a `PaymentResult`.
- Stripe helpers (`getStripeProviderId()`, `importStripeToken()`, …): Deprecated proxies, use `Odoo::$stripe` (`Addon\Stripe\StripeService`)
  with explicit company and test/live state via `requireStripeProviderId()`.
- `downloadPdf(int $id)`: Download the binary PDF content of an invoice.
- `getPortalUrl(int $id)`: Generate a secure, one-click access link for the customer.

### CustomerService

Manages Odoo customers/partners (`res.partner`).

- `list(array $domain, QueryOptions|int|null $options)`: Returns matching customers without a hidden cap.
- `iterate(array $domain, ?QueryOptions $options)`: Lazily yields customers in stable pages.
- `get(int $id)`: Returns a `Customer` (includes `paymentTokenCount`).
- `getPaymentTokens()` / `iteratePaymentTokens()`: Returns or iterates saved `PaymentToken` models.
- `search(string $query)`: Searches customers by name, email, or reference.
- `findByName(string $name, bool $exact = false)`: Find customers specifically by name.
- `getByCountry(string $code)`: Finds customers by ISO country code.
- `getPortalUrl(int $id)`: Generate a secure, one-click access link for the subscription.
- `findByEmail(string $email)`: Exact match lookup by email.
- `listPeppolEnabled()`: Deprecated PEPPOL proxy, use `Odoo::$peppol`.

---

## PEPPOL Electronic Invoicing

This library supports [Odoo's PEPPOL integration](https://www.odoo.com/documentation/master/applications/finance/accounting/customer_invoices/electronic_invoicing.html) for sending and receiving electronic invoices over the PEPPOL network.

### Customer Fields

The `Customer` model includes PEPPOL configuration fields:

| Field                | Description                                           |
|----------------------|-------------------------------------------------------|
| `peppolEas`          | EAS code (e.g., `0208` for Belgium, `0088` for EAN)   |
| `peppolEndpoint`     | Endpoint identifier (usually VAT or company registry) |
| `invoiceEdiFormat`   | Preferred e-invoice format (e.g., `peppol_bis3`)      |

**Helper Methods:**

- `hasPeppolConfig()`: Returns `true` if EAS and endpoint are configured.
- `isPeppolEnabled()`: Returns `true` if customer can receive PEPPOL invoices.

### Invoice Fields

The `Invoice` model includes PEPPOL status tracking:

| Field             | Description                                                  |
|-------------------|--------------------------------------------------------------|
| `peppolMoveState` | Status: `to_send`, `processing`, `done`, `error`, `canceled` |

**Helper Methods:**

- `isPeppolReady()`: Returns `true` if invoice is posted and ready to send.
- `isPeppolSent()`: Returns `true` if successfully transmitted.
- `hasPeppolError()`: Returns `true` if transmission failed.
- `getPeppolStatusLabel()`: Returns human-readable status string.

### Usage Example

```php
// Find customers with PEPPOL configuration
$peppolCustomers = $app->peppol->listEnabledCustomers();

foreach ($peppolCustomers as $customer) {
    echo "{$customer->name}: EAS={$customer->peppolEas}, Endpoint={$customer->peppolEndpoint}\n";
}

// Find invoices ready for PEPPOL transmission
$readyInvoices = $app->peppol->listReadyInvoices();

foreach ($readyInvoices as $invoice) {
    echo "{$invoice->name}: {$invoice->getPeppolStatusLabel()}\n";
}
```

See [peppol.php](../examples/peppol.php) for a full implementation example.

## Webhook Support

This library is designed as a **pull-only** API client (i.e., your application makes calls to Odoo). It does not provide built-in mechanisms to listen or respond to incoming webhooks triggered by Odoo (such as payment reconciliations or an invoice being cancelled by an accountant).

If your application needs to react to Odoo events, **webhook ingestion must be implemented within your application layer**, separate from this library.

---

## Error Handling

All API errors throw an `LeKoala\OdooClient\OdooException`. Always wrap your calls in a try-catch block:

```php
try {
    $app->invoices->confirm($id);
} catch (\LeKoala\OdooClient\OdooException $e) {
    echo "Caught Odoo Error: " . $e->getMessage();
}
```

---

## Examples

See the `examples/` directory for implementation details:

- `subscriptions.php`
- `invoices.php`
- `products.php`
- `payments.php`
- `customers.php`
- `peppol.php`
- `subscription_plans.php`
- `payment_status.php`
- `portal_and_pdf.php`
- `domain_builder.php`

---

## Domain Builder (Fluent Filters)

The `Domain` helper allows you to build Odoo search filters without manually managing nested arrays and logical operators.

```php
use LeKoala\OdooClient\Domain;

$domain = Domain::build()
    ->where('is_company', '=', true)
    ->orWhere('name', 'ilike', 'Azure')
    ->get();

// Pass it directly to list() or search() methods
$customers = $app->customers->list($domain);
```

It automatically handles prefixes (`|`, `&`) for complex logic:

```php
// (A AND B) OR C
$domain = Domain::build()
    ->where('state', '=', 'posted')
    ->where('move_type', '=', 'out_invoice')
    ->orWhere('amount_total', '>', 1000)
    ->get();
```

---

## Direct Client Usage (No Services)

For models not covered by the standard service layer, you can use the `Client` directly.

```php
$client = $app->getClient();

// Server Info
$version = $client->version();

// Data Discovery
$fields = $client->fieldsOf('res.partner');

// Fluent Search & Read
$records = $client->searchRead(
    'res.partner', 
    Domain::build()->where('is_company', '=', true)->get(),
    ['name', 'email'],
    10 // limit
);

// Create
$id = $client->create('res.partner', ['name' => 'John Doe']);

// Update
$client->write('res.partner', [$id], ['name' => 'John Updated']);

// Delete
$client->unlink('res.partner', [$id]);
```

See [direct_usage.php](../examples/direct_usage.php) for a full script.
