<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Client;
use LeKoala\OdooClient\Config;
use LeKoala\OdooClient\Enums\OdooModel;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Mock Client for testing services without hitting the API.
 */
class MockClient extends Client
{
    /** @var array<string, mixed> */
    private array $mockedResponses = [];
    /** @var array<string, array<int, mixed>> */
    private array $mockedResponseQueues = [];
    /** @var array<int, array{model: string, method: string, params: array<string, mixed>}> */
    private array $callLog = [];
    /** @var \LeKoala\OdooClient\Events\OdooEvent[] */
    private array $dispatchedEvents = [];
    private LoggerInterface $logger;
    private ?EventDispatcherInterface $eventDispatcher = null;
    private Config $config;
    private string $apiKey = 'mock-current-key';
    /** @var array<string, \Throwable> */
    private array $mockedExceptions = [];

    public function __construct()
    {
        $this->logger = new NullLogger();
        $this->config = (new Config('http://odoo.local', 'test_db', $this->apiKey))
            ->setLogger($this->logger);
        parent::__construct($this->config);
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger ?? new NullLogger();
        $this->config->setLogger($this->logger);
    }

    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    public function setEventDispatcher(?EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function getBaseUrl(): string
    {
        return 'http://odoo.local';
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
        $this->config = (new Config('http://odoo.local', 'test_db', $this->apiKey))
            ->setLogger($this->logger);
        parent::__construct($this->config);
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function mockResponse(OdooModel|string $model, string $method, mixed $response): void
    {
        $modelName = $model instanceof OdooModel ? $model->value : $model;
        $key = "{$modelName}.{$method}";
        $this->mockedResponses[$key] = $response;
    }

    /**
     * @param array<int, mixed> $responses
     */
    public function mockResponseSequence(OdooModel|string $model, string $method, array $responses): void
    {
        $modelName = $model instanceof OdooModel ? $model->value : $model;
        $key = "{$modelName}.{$method}";
        $this->mockedResponseQueues[$key] = $responses;
    }

    public function mockException(OdooModel|string $model, string $method, \Throwable $error): void
    {
        $modelName = $model instanceof OdooModel ? $model->value : $model;
        $this->mockedExceptions["{$modelName}.{$method}"] = $error;
    }

    public function version(): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->call('common', 'version') ?? [];
        return $result;
    }

    public function authenticate(): bool
    {
        return is_array($this->call('res.users', 'context_get'));
    }

    public function fieldsOf(OdooModel|string $model, array $context = []): array
    {
        // Delegate to the parent so the real fields_get cache (and
        // clearFieldsCache()) is exercised, backed by mocked call() responses.
        return parent::fieldsOf($model, $context);
    }

    public function call(OdooModel|string $model, string $method, array $params = []): mixed
    {
        $modelName = $model instanceof OdooModel ? $model->value : $model;
        $this->callLog[] = [
            'model' => $modelName,
            'method' => $method,
            'params' => $params,
        ];

        return $this->respondTo($modelName, $method, $params);
    }

    /** @param array<string, mixed> $params */
    private function respondTo(string $modelName, string $method, array $params): mixed
    {
        $key = "{$modelName}.{$method}";

        $this->logger->debug("Mock executing $key");

        if ($modelName === 'sale.subscription') {
            $key = "sale.order.{$method}";
        }

        if (isset($this->mockedExceptions[$key])) {
            throw $this->mockedExceptions[$key];
        }

        if (!empty($this->mockedResponseQueues[$key])) {
            $queued = count($this->mockedResponseQueues[$key]) > 1
                ? array_shift($this->mockedResponseQueues[$key])
                : $this->mockedResponseQueues[$key][0];

            return $queued instanceof \Closure ? $queued($params) : $queued;
        }

        $response = $this->mockedResponses[$key] ?? null;

        return $response instanceof \Closure ? $response($params) : $response;
    }

    /**
     * @param array<int, mixed> $domain
     * @param array<string, mixed> $kwargs
     * @return array<int, mixed>
     */
    public function search(OdooModel|string $model, array $domain = [], array $kwargs = []): array
    {
        /** @var array<int, mixed> $result */
        $result = $this->call($model, 'search', array_merge(['domain' => $domain], $kwargs)) ?? [];
        return $result;
    }

    public function searchRead(OdooModel|string $model, array $domain = [], array $fields = [], int $limit = 0, int $offset = 0, ?string $order = null, array $context = []): array
    {
        $params = ['domain' => $domain];
        if ($fields !== []) {
            $params['fields'] = $fields;
        }
        if ($limit > 0) {
            $params['limit'] = $limit;
        }
        if ($offset > 0) {
            $params['offset'] = $offset;
        }
        if ($order !== null) {
            $params['order'] = $order;
        }
        if ($context !== []) {
            $params['context'] = $context;
        }

        /** @var array<int, mixed> $result */
        $result = $this->call($model, 'search_read', $params) ?? [];
        return $result;
    }

    public function read(OdooModel|string $model, array $ids, array $fields = [], array $context = []): array
    {
        /** @var array<int, mixed> $result */
        $params = ['ids' => $ids];
        if ($fields !== []) {
            $params['fields'] = $fields;
        }
        if ($context !== []) {
            $params['context'] = $context;
        }
        $result = $this->call($model, 'read', $params) ?? [];
        return $result;
    }

    public function create(OdooModel|string $model, array $values, array $context = []): int
    {
        $params = ['vals_list' => [$values]];
        if ($context !== []) {
            $params['context'] = $context;
        }
        $result = $this->call($model, 'create', $params);
        if (is_array($result)) {
            $result = $result[0] ?? null;
        }
        return is_numeric($result) ? (int) $result : 0;
    }

    public function write(OdooModel|string $model, array $ids, array $values, array $context = []): bool
    {
        $params = ['ids' => $ids, 'vals' => $values];
        if ($context !== []) {
            $params['context'] = $context;
        }
        return (bool) $this->call($model, 'write', $params);
    }

    /**
     * @return array<int, array{model: string, method: string, params: array<string, mixed>}>
     */
    public function getCallLog(): array
    {
        return $this->callLog;
    }

    /**
     * Store dispatched events.
     */
    public function dispatchEvent(object $event): void
    {
        if ($event instanceof \LeKoala\OdooClient\Events\OdooEvent) {
            $this->dispatchedEvents[] = $event;
        }
        parent::dispatchEvent($event);
    }

    /**
     * @return \LeKoala\OdooClient\Events\OdooEvent[]
     */
    public function getDispatchedEvents(): array
    {
        return $this->dispatchedEvents;
    }
    /**
     * @return array{model: string, method: string, params: array<string, mixed>}
     */
    public function getLastCall(): array
    {
        $last = end($this->callLog);
        return $last !== false
            ? $last
            : ['model' => '', 'method' => '', 'params' => []];
    }
}
