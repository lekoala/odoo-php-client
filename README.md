# Odoo PHP Client

A PHP 8.3+ client for Odoo 19's External JSON-2 API, with generic model calls and optional typed services for partners, invoices, subscriptions, products, payments, API keys, portal PDFs, Stripe and Peppol.

## Installation

```bash
composer require lekoala/odoo-php-client
```

## Quick start

```php
use LeKoala\OdooClient\Config;
use LeKoala\OdooClient\Odoo;

$odoo = new Odoo(new Config(
    url: 'https://mycompany.odoo.com',
    db: 'mycompany',
    apiKey: $_ENV['ODOO_API_KEY'],
));

$partners = $odoo->getClient()->searchRead(
    'res.partner',
    [['is_company', '=', true]],
    ['id', 'name', 'email'],
    limit: 20,
    order: 'name asc',
);
```

Every JSON-2 method is available through named parameters:

```php
$odoo->getClient()->call('sale.order', 'action_confirm', [
    'ids' => [123],
]);
```

Single and batch creation have distinct return contracts:

```php
$id = $odoo->getClient()->create('res.partner', ['name' => 'Alice']);

$ids = $odoo->getClient()->createMany('res.partner', [
    ['name' => 'Alice'],
    ['name' => 'Bob'],
]);
```

## Pagination and iteration

Typed service lists apply no hidden result cap. Use `QueryOptions` for an
explicit page, or `iterate()` to process large result sets in bounded requests:

```php
use LeKoala\OdooClient\QueryOptions;

$page = $odoo->customers->list([], new QueryOptions(
    limit: 50,
    offset: 100,
    order: 'name asc',
    fields: ['id', 'name', 'email'],
    context: ['lang' => 'fr_BE'],
));

foreach ($odoo->customers->iterate(
    options: new QueryOptions(batchSize: 200, order: 'name asc'),
) as $customer) {
    // The effective order is "name asc, id asc" for stable page boundaries.
}
```

`limit: null` (the default) means no overall cap. Iterators fetch by
`batchSize`; array-returning `list()` methods load all matching rows, so prefer
`iterate()` for large tables. Passing an integer as the second argument is
shorthand for an explicit limit.

Low-level `searchRead()` sends only the options you actually provide: an empty
`$fields`, a zero limit/offset or a null order is omitted from the request body
instead of being sent as `[]`/`0`/`null`.

See [the complete documentation](docs/index.md), [input DTOs](docs/inputs.md), [models](docs/models.md), and [examples](examples/).

AI agents: the generic integration skill shipped with this package is
[.agents/skills/odoo-php-client/SKILL.md](.agents/skills/odoo-php-client/SKILL.md).

## Design guarantees

- The public entry point is `call()` with explicit JSON-2 named parameters.
- Read-only calls may be retried after transient gateway/network failures; writes and actions are not retried automatically.
  Retry count, backoff, timeouts and redirects are configurable on `Config` (`setMaxRetries()`, `setRetryBackoff()`,
  `setConnectTimeout()`, `setTimeout()`, `setMaxRedirects()`).
- A default `context` can be set once with `Config::setDefaultContext()`; explicit per-call context wins key by key.
- `Client::fieldsOf()` caches `fields_get` per instance; call `clearFieldsCache()` after module upgrades.
- Events are dispatched after the remote mutation has committed: a listener exception is logged, never reported as an Odoo failure.
- Stripe and Peppol are exposed as `Addon\Stripe` and `Addon\Peppol` (`Odoo::$stripe`, `Odoo::$peppol`).
- HTTP 401, HTTP 403, network failures and Odoo validation errors use distinct exception types.
- HTTP calls use an injectable transport; cURL is only the zero-configuration fallback.
- A custom adapter can be injected into `Client` for tests or alternative transports.
- Each JSON-2 call is a separate Odoo transaction. Multi-call business workflows are not atomic.

## HTTP transport

The public HTTP boundary is transport-agnostic. With `ext-curl` installed, no additional
configuration is needed. To reuse Guzzle, Symfony HttpClient, or another PSR-18 client,
inject `Psr18Transport` through the configuration.

Guzzle:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use LeKoala\OdooClient\Http\Psr18Transport;
use LeKoala\OdooClient\Config;

$guzzle = new Client([
    'connect_timeout' => 5,
    'timeout' => 30,
]);
$factory = new HttpFactory();

$config = (new Config($url, $db, $apiKey))->setHttpTransport(
    new Psr18Transport($guzzle, $factory, $factory)
);
```

Symfony HttpClient:

```php
use LeKoala\OdooClient\Http\Psr18Transport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\HttpClient as SymfonyHttpClient;
use Symfony\Component\HttpClient\Psr18Client;

$psr18 = new Psr18Client(SymfonyHttpClient::create([
    'timeout' => 30,
]));
$factory = new Psr17Factory();

$config->setHttpTransport(new Psr18Transport($psr18, $factory, $factory));
```

Redirects are handled consistently by this package. Sensitive JSON-2 headers are removed
when a redirect changes origin, and portal PDF requests never receive the API bearer token.

## Development

```bash
composer test
composer sa
composer validate --strict
```

## License

MIT. See [LICENSE](LICENSE).
