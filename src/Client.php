<?php

namespace LeKoala\OdooClient;

use LeKoala\OdooClient\Adapters\OdooAdapterInterface;
use LeKoala\OdooClient\Adapters\JsonAdapter;
use LeKoala\OdooClient\Legacy\LegacyRpcArgumentMapper;
use LeKoala\OdooClient\Enums\OdooModel;
use LeKoala\OdooClient\Exceptions\OdooNetworkException;
use LeKoala\OdooClient\Events\OdooRecordCreatedEvent;
use LeKoala\OdooClient\Events\OdooRecordUpdatedEvent;
use LeKoala\OdooClient\Events\OdooRecordDeletedEvent;
use LeKoala\OdooClient\Http\CurlTransport;
use LeKoala\OdooClient\Http\HttpClient;
use LeKoala\OdooClient\Http\HttpTransportInterface;
use Psr\Log\LoggerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class Client
{
    private Config $config;
    private OdooAdapterInterface $adapter;
    private LoggerInterface $logger;
    private ?EventDispatcherInterface $eventDispatcher = null;
    private ?HttpClient $httpClient = null;
    /** @var array<string, array<string, mixed>> Cache for model fields */
    private array $fieldCache = [];

    /** @var callable(int): void Sleeps for the given microseconds between retries */
    private $sleeper;

    /**
     * @var array<int, string>
     */
    private const REDACTED_KEY_PARTS = [
        'email', 'phone', 'mobile', 'street', 'city', 'zip',
        'password', 'token', 'login', 'apikey', 'api_key', 'secret',
        'peppol_endpoint', 'bank', 'acc_number', 'iban', 'birth', 'inami',
    ];

    /**
     * @var array<int, string>
     */
    private const REDACTED_KEY_WORDS = ['vat'];

    /**
     * @var array<int, string>
     */
    private const REDACTED_EXACT_KEYS = [
        'name', 'display_name', 'complete_name', 'partner_name', 'contact_name',
        'firstname', 'lastname', 'admin_firstname', 'admin_lastname',
    ];

    /**
     * @var array<int, string>
     */
    private const RETRYABLE_METHODS = [
        'search',
        'search_count',
        'read',
        'search_read',
        'read_group',
        'fields_get',
        'name_search',
        'name_get',
        'check_access_rights',
        'version',
    ];

    /**
     * @param callable(int): void|null $sleeper Sleeps between retries; defaults to usleep().
     */
    public function __construct(
        Config $config,
        ?OdooAdapterInterface $adapter = null,
        ?HttpTransportInterface $httpTransport = null,
        ?callable $sleeper = null,
    )
    {
        $this->config = $config;
        $this->sleeper = $sleeper ?? static function (int $micros): void {
            if ($micros > 0) {
                usleep($micros);
            }
        };
        $this->httpClient = new HttpClient(
            $httpTransport ?? $config->getHttpTransport() ?? new CurlTransport(
                $config->getConnectTimeout(),
                $config->getTimeout()
            )
        );
        $this->adapter = $adapter ?? $this->createAdapter($config);
        $this->logger = $config->getLogger();
        $this->eventDispatcher = $config->getEventDispatcher();
    }

    /**
     * Override the sleep between retries (useful for tests to avoid waiting).
     *
     * @param callable(int): void|null $sleeper
     */
    public function setSleeper(?callable $sleeper): void
    {
        $this->sleeper = $sleeper ?? static function (int $micros): void {
            if ($micros > 0) {
                usleep($micros);
            }
        };
    }

    private function createAdapter(Config $config): OdooAdapterInterface
    {
        return new JsonAdapter($config, $this->getHttpClient());
    }

    public function getHttpClient(): HttpClient
    {
        return $this->httpClient ??= new HttpClient(
            $this->getConfig()->getHttpTransport() ?? new CurlTransport(
                $this->getConfig()->getConnectTimeout(),
                $this->getConfig()->getTimeout()
            )
        );
    }

    /**
     * Get Odoo server version information.
     * @return array<string, mixed>
     */
    public function version(): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->call('common', 'version');
        return $result;
    }

    public function getBaseUrl(): string
    {
        return $this->config->getUrl();
    }

    public function getDb(): string
    {
        return $this->config->getDb();
    }

    public function getApiKey(): string
    {
        return $this->config->getApiKey();
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Authenticate with Odoo
     */
    public function authenticate(): bool
    {
        return $this->adapter->authenticate();
    }

    /**
     * Low-level JSON-2 call. Only transient network failures on read-only
     * methods are retried; writes and actions are never retried automatically.
     * Each call is a separate Odoo transaction: after a timeout, reconcile
     * the remote state before replaying.
     *
     * @param OdooModel|string $model
     * @param string $method
     * @param array<string, mixed> $params Named parameters, sent as the request body
     * @return mixed
     */
    public function call(OdooModel|string $model, string $method, array $params = []): mixed
    {
        $modelName = $model instanceof OdooModel ? $model->value : $model;
        $maxRetries = $this->config->getMaxRetries();
        $attempts = 0;

        while (true) {
            $attempts++;
            try {
                $this->logger->debug("Odoo request: {$modelName}::{$method}", [
                    'params' => self::redactForLog($params),
                    'attempt' => $attempts,
                ]);

                return $this->adapter->call($modelName, $method, $params);
            } catch (OdooNetworkException $e) {
                $this->logger->warning("Odoo network error on {$modelName}::{$method}: " . $e->getMessage(), [
                    'attempt' => $attempts,
                    'maxRetries' => $maxRetries,
                ]);

                if (!in_array($method, self::RETRYABLE_METHODS, true)) {
                    throw $e;
                }

                if ($attempts > $maxRetries) {
                    throw $e;
                }

                ($this->sleeper)($this->retryDelayMicros($attempts));
            } catch (\Exception $e) {
                $this->logger->error("Odoo error on {$modelName}::{$method}: " . $e->getMessage(), [
                    'exception' => get_class($e),
                    'code' => $e->getCode(),
                ]);
                throw $e;
            }
        }
    }

    private function retryDelayMicros(int $attempt): int
    {
        $baseMs = $this->config->getRetryBackoffBaseMs();
        $multiplier = $this->config->getRetryBackoffMultiplier();

        return (int) ($baseMs * 1000 * pow($multiplier, $attempt - 1));
    }

    /**
     * Execute a legacy RPC-shaped call by translating positional arguments.
     *
     * @param OdooModel|string $model
     * @param string $method
     * @param array<int, mixed> $args
     * @param array<string, mixed> $kwargs
     * @return mixed
     * @deprecated Use {@see self::call()} with explicit JSON-2 named parameters.
     */
    public function execute(OdooModel|string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        return $this->call(
            $model,
            $method,
            (new LegacyRpcArgumentMapper())->toNamedParams($method, $args, $kwargs),
        );
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function redactForLog(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return '***';
        }

        if (is_array($value)) {
            if (array_is_list($value)
                && count($value) >= 3
                && isset($value[0])
                && is_string($value[0])
                && self::isRedactedKey($value[0])
            ) {
                $value[2] = self::mask($value[2] ?? null);
            }

            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && self::isRedactedKey($key)) {
                    $out[$key] = self::mask($item);
                    continue;
                }
                $out[$key] = self::redactForLog($item, $depth + 1);
            }
            return $out;
        }

        if (is_string($value)) {
            return strlen($value) > 40 ? self::mask($value) : $value;
        }

        if (is_object($value)) {
            return '<' . get_class($value) . '>';
        }

        return $value;
    }

    private static function isRedactedKey(string $key): bool
    {
        $key = strtolower($key);
        if (in_array($key, self::REDACTED_EXACT_KEYS, true)) {
            return true;
        }
        foreach (self::REDACTED_KEY_PARTS as $part) {
            if (str_contains($key, $part)) {
                return true;
            }
        }
        foreach (self::REDACTED_KEY_WORDS as $word) {
            if (preg_match('/(^|_)' . $word . '($|_)/', $key) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $value
     */
    private static function mask(mixed $value): string
    {
        if (is_array($value)) {
            return '<array:' . count($value) . '>';
        }
        if (is_string($value)) {
            return '<string:' . strlen($value) . '>';
        }
        return '***';
    }

    /**
     * Dispatch a PSR-14 event if a dispatcher is configured.
     *
     * Events are dispatched after the remote mutation has succeeded: a
     * listener exception never means the Odoo call failed and never rolls
     * the remote change back. Listener failures are logged and swallowed so
     * the caller still observes the successful Odoo result.
     */
    public function dispatchEvent(object $event): void
    {
        if ($this->eventDispatcher) {
            try {
                $this->eventDispatcher->dispatch($event);
            } catch (\Throwable $e) {
                $this->logger->warning('Odoo event listener failed after the remote mutation succeeded.', [
                    'event' => get_class($event),
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    // Helper methods for common operations.

    /**
     * @param OdooModel|string $model
     * @param array<int, mixed> $domain
     * @param array<string, mixed> $kwargs
     * @return array<int, mixed>
     */
    public function search(OdooModel|string $model, array $domain = [], array $kwargs = []): array
    {
        /** @var array<int, mixed> $result */
        $result = $this->call($model, 'search', array_merge(['domain' => $domain], $kwargs));
        return $result;
    }

    /**
     * @param OdooModel|string $model
     * @param array<int> $ids
     * @param array<int, string> $fields
     * @param array<string, mixed> $context
     * @return array<int, mixed>
     */
    public function read(OdooModel|string $model, array $ids, array $fields = [], array $context = []): array
    {
        $params = ['ids' => $ids];
        if (!empty($fields)) {
            $params['fields'] = $fields;
        }
        if ($context !== []) {
            $params['context'] = $context;
        }
        /** @var array<int, mixed> $result */
        $result = $this->call($model, 'read', $params);
        return $result;
    }

    /**
     * @param OdooModel|string $model
     * @param array<int, mixed> $domain
     * @param array<string, mixed> $options
     */
    public function searchCount(OdooModel|string $model, array $domain = [], array $options = []): int
    {
        $result = $this->call($model, 'search_count', array_merge(['domain' => $domain], $options));

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * @param OdooModel|string $model
     * @param array<int, string> $fields
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function find(OdooModel|string $model, int $id, array $fields = [], array $context = []): ?array
    {
        $records = $this->read($model, [$id], $fields, $context);
        $record = $records[0] ?? null;

        if (!is_array($record)) {
            return null;
        }

        /** @var array<string, mixed> $record */
        return $record;
    }

    /**
     * @param OdooModel|string $model
     * @param array<int, mixed> $domain
     * @param array<int, string> $fields
     * @param array<int, string> $groupBy
     * @param array<string, mixed> $options
     * @return array<int, mixed>
     */
    public function readGroup(
        OdooModel|string $model,
        array $domain,
        array $fields,
        array $groupBy,
        array $options = []
    ): array {
        /** @var array<int, mixed> $result */
        $result = $this->call($model, 'read_group', array_merge([
            'domain' => $domain,
            'fields' => $fields,
            'groupby' => $groupBy,
        ], $options));

        return $result;
    }

    /**
     * @param OdooModel|string $model
     * @param array<int, mixed> $domain
     * @param array<int, string> $fields
     * @param int $limit
     * @param int $offset
     * @param string|null $order
     * @param array<string, mixed> $context
     * @return array<int, mixed>
     */
    public function searchRead(OdooModel|string $model, array $domain = [], array $fields = [], int $limit = 0, int $offset = 0, ?string $order = null, array $context = []): array
    {
        $params = [
            'domain' => $domain,
            'fields' => $fields,
            'limit' => $limit,
            'offset' => $offset,
        ];

        if ($order !== null) {
            $params['order'] = $order;
        }

        if ($context !== []) {
            $params['context'] = $context;
        }

        /** @var array<int, mixed> $result */
        $result = $this->call($model, 'search_read', $params);
        return $result;
    }

    /**
     * Lazily read every matching record in deterministic pages.
     *
     * @param OdooModel|string $model
     * @param array<int, mixed> $domain
     * @param array<int, string> $fields
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateSearchRead(
        OdooModel|string $model,
        array $domain = [],
        array $fields = [],
        ?QueryOptions $options = null,
    ): \Generator {
        $options ??= new QueryOptions();
        $offset = $options->offset;
        $remaining = ($options->limit ?? 0) > 0 ? $options->limit : null;

        while ($remaining === null || $remaining > 0) {
            $pageSize = $remaining === null
                ? $options->batchSize
                : min($options->batchSize, $remaining);

            $records = $this->searchRead(
                $model,
                $domain,
                $fields,
                $pageSize,
                $offset,
                $options->stableOrder(),
                $options->context,
            );

            $count = count($records);
            foreach ($records as $record) {
                if (is_array($record)) {
                    /** @var array<string, mixed> $record */
                    yield $record;
                }
            }

            if ($count < $pageSize) {
                break;
            }

            $offset += $count;
            if ($remaining !== null) {
                $remaining -= $count;
            }
        }
    }

    /**
     * @param OdooModel|string $model
     * @param array<string, mixed> $values
     * @param array<string, mixed> $context
     * @return int
     *
     * The created-event is dispatched after Odoo has committed: a listener
     * failure does not mean the creation failed.
     */
    public function create(OdooModel|string $model, array $values, array $context = []): int
    {
        $params = ['vals_list' => [$values]];
        if ($context !== []) {
            $params['context'] = $context;
        }
        $result = $this->call($model, 'create', $params);
        $id = self::firstPositiveId($result);

        if ($id > 0) {
            $modelName = $model instanceof OdooModel ? $model->value : $model;
            $this->dispatchEvent(new OdooRecordCreatedEvent($modelName, $id, $values));
        }

        return $id;
    }

    /**
     * Create several records and preserve every identifier returned by Odoo.
     *
     * @param OdooModel|string $model
     * @param array<int, array<string, mixed>> $values
     * @param array<string, mixed> $context
     * @return array<int>
     */
    public function createMany(OdooModel|string $model, array $values, array $context = []): array
    {
        if ($values === []) {
            return [];
        }

        $params = ['vals_list' => array_values($values)];
        if ($context !== []) {
            $params['context'] = $context;
        }
        $result = $this->call($model, 'create', $params);
        if (!is_array($result)) {
            return [];
        }

        $ids = [];
        foreach ($result as $index => $rawId) {
            if (!is_numeric($rawId) || (int) $rawId <= 0) {
                continue;
            }

            $id = (int) $rawId;
            $ids[] = $id;
            $modelName = $model instanceof OdooModel ? $model->value : $model;
            $this->dispatchEvent(new OdooRecordCreatedEvent($modelName, $id, $values[$index] ?? []));
        }

        return $ids;
    }

    /**
     * @param OdooModel|string $model
     * @param array<int> $ids
     * @param array<string, mixed> $values
     * @param array<string, mixed> $context
     * @return bool
     *
     * The updated-events are dispatched after Odoo has committed: a listener
     * failure does not mean the write failed.
     */
    public function write(OdooModel|string $model, array $ids, array $values, array $context = []): bool
    {
        $params = ['ids' => $ids, 'vals' => $values];
        if ($context !== []) {
            $params['context'] = $context;
        }
        $success = (bool) $this->call($model, 'write', $params);

        if ($success) {
            $modelName = $model instanceof OdooModel ? $model->value : $model;
            foreach ($ids as $id) {
                $this->dispatchEvent(new OdooRecordUpdatedEvent($modelName, $id, $values));
            }
        }

        return $success;
    }

    /**
     * @param OdooModel|string $model
     * @param array<int> $ids
     * @param array<string, mixed> $context
     * @return bool
     *
     * The deleted-events are dispatched after Odoo has committed: a listener
     * failure does not mean the unlink failed.
     */
    public function unlink(OdooModel|string $model, array $ids, array $context = []): bool
    {
        $params = ['ids' => $ids];
        if ($context !== []) {
            $params['context'] = $context;
        }
        $success = (bool) $this->call($model, 'unlink', $params);

        if ($success) {
            $modelName = $model instanceof OdooModel ? $model->value : $model;
            foreach ($ids as $id) {
                $this->dispatchEvent(new OdooRecordDeletedEvent($modelName, $id));
            }
        }

        return $success;
    }

    private static function firstPositiveId(mixed $result): int
    {
        if (is_array($result)) {
            $result = $result[0] ?? null;
        }

        return is_numeric($result) && (int) $result > 0 ? (int) $result : 0;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function fieldsOf(OdooModel|string $model, array $context = []): array
    {
        $modelName = $model instanceof OdooModel ? $model->value : $model;
        $cacheKey = $modelName . ':' . hash('sha256', serialize($context));

        if (isset($this->fieldCache[$cacheKey])) {
            return $this->fieldCache[$cacheKey];
        }

        $params = ['attributes' => ['string', 'help', 'type']];
        if ($context !== []) {
            $params['context'] = $context;
        }
        $result = $this->call($modelName, 'fields_get', $params);

        if (!is_array($result)) {
            $result = [];
        }

        /** @var array<string, mixed> $result */
        $this->fieldCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Explicitly invalidate the fields_get cache.
     *
     * The cache is per Client instance and keyed by model + context. Pass a
     * model (and optionally its context) to invalidate a subset, or nothing
     * to clear everything — e.g. after a module upgrade that adds fields.
     *
     * @param array<string, mixed>|null $context Null clears every context of the model.
     */
    public function clearFieldsCache(OdooModel|string|null $model = null, ?array $context = null): void
    {
        if ($model === null) {
            $this->fieldCache = [];
            return;
        }

        $modelName = $model instanceof OdooModel ? $model->value : $model;

        if ($context === null) {
            foreach (array_keys($this->fieldCache) as $key) {
                if (str_starts_with($key, $modelName . ':')) {
                    unset($this->fieldCache[$key]);
                }
            }
            return;
        }

        unset($this->fieldCache[$modelName . ':' . hash('sha256', serialize($context))]);
    }

    /**
     * Filters requested fields to only those that actually exist on the model.
     *
     * @param OdooModel|string $model
     * @param array<int, string> $requestedFields
     * @param array<string, mixed> $context
     * @return array<int, string>
     */
    public function getSafeFields(OdooModel|string $model, array $requestedFields, array $context = []): array
    {
        $availableFields = $this->fieldsOf($model, $context);
        if (empty($availableFields)) {
            return $requestedFields;
        }

        return array_values(array_intersect($requestedFields, array_keys($availableFields)));
    }
}
