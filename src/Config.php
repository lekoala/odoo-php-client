<?php

namespace LeKoala\OdooClient;

use LeKoala\OdooClient\Http\HttpTransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\EventDispatcher\EventDispatcherInterface;

class Config
{
    private string $url;
    private string $db;
    private string $apiKey;
    private LoggerInterface $logger;
    private ?EventDispatcherInterface $eventDispatcher = null;
    private ?OdooApiKeyProvider $apiKeyProvider = null;
    private ?HttpTransportInterface $httpTransport = null;
    private int $maxRetries = 2;
    private int $retryBackoffBaseMs = 500;
    private float $retryBackoffMultiplier = 2.0;
    private int $connectTimeout = 5;
    private int $timeout = 30;
    private int $maxRedirects = 5;

    public function __construct(?string $url = null, ?string $db = null, ?string $apiKey = null)
    {
        $this->url = rtrim($url ?? '', '/');
        $this->db = $db ?? '';
        $this->apiKey = $apiKey ?? '';
        $this->logger = new NullLogger();
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getDb(): string
    {
        return $this->db;
    }

    public function getApiKey(): string
    {
        $provided = $this->apiKeyProvider?->getApiKey() ?? '';

        return $provided !== '' ? $provided : $this->apiKey;
    }

    public function getBootstrapApiKey(): string
    {
        return $this->apiKey;
    }

    public function getApiKeyProvider(): ?OdooApiKeyProvider
    {
        return $this->apiKeyProvider;
    }

    public function setApiKeyProvider(?OdooApiKeyProvider $provider): self
    {
        $this->apiKeyProvider = $provider;
        return $this;
    }

    public function withApiKey(string $apiKey): self
    {
        $clone = new self($this->url, $this->db, $apiKey);
        $clone->setLogger($this->logger);
        $clone->setEventDispatcher($this->eventDispatcher);
        $clone->setHttpTransport($this->httpTransport);
        $clone->setMaxRetries($this->maxRetries);
        $clone->setRetryBackoff($this->retryBackoffBaseMs, $this->retryBackoffMultiplier);
        $clone->setConnectTimeout($this->connectTimeout);
        $clone->setTimeout($this->timeout);
        $clone->setMaxRedirects($this->maxRedirects);

        return $clone;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(?LoggerInterface $logger): self
    {
        $this->logger = $logger ?? new NullLogger();
        return $this;
    }

    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    public function setEventDispatcher(?EventDispatcherInterface $dispatcher): self
    {
        $this->eventDispatcher = $dispatcher;
        return $this;
    }

    public function getHttpTransport(): ?HttpTransportInterface
    {
        return $this->httpTransport;
    }

    public function setHttpTransport(?HttpTransportInterface $httpTransport): self
    {
        $this->httpTransport = $httpTransport;
        return $this;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = max(0, $maxRetries);
        return $this;
    }

    public function getRetryBackoffBaseMs(): int
    {
        return $this->retryBackoffBaseMs;
    }

    public function getRetryBackoffMultiplier(): float
    {
        return $this->retryBackoffMultiplier;
    }

    public function setRetryBackoff(int $baseMs, float $multiplier = 2.0): self
    {
        $this->retryBackoffBaseMs = max(0, $baseMs);
        $this->retryBackoffMultiplier = $multiplier >= 1.0 ? $multiplier : 1.0;
        return $this;
    }

    public function getConnectTimeout(): int
    {
        return $this->connectTimeout;
    }

    public function setConnectTimeout(int $seconds): self
    {
        $this->connectTimeout = max(1, $seconds);
        return $this;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function setTimeout(int $seconds): self
    {
        $this->timeout = max(1, $seconds);
        return $this;
    }

    public function getMaxRedirects(): int
    {
        return $this->maxRedirects;
    }

    public function setMaxRedirects(int $maxRedirects): self
    {
        $this->maxRedirects = max(0, $maxRedirects);
        return $this;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $instance = new self(
            isset($config['url']) && is_string($config['url']) ? $config['url'] : '',
            isset($config['db']) && is_string($config['db']) ? $config['db'] : '',
            self::apiKeyFromArray($config)
        );

        if (isset($config['logger']) && $config['logger'] instanceof LoggerInterface) {
            $instance->setLogger($config['logger']);
        }

        if (isset($config['eventDispatcher']) && $config['eventDispatcher'] instanceof EventDispatcherInterface) {
            $instance->setEventDispatcher($config['eventDispatcher']);
        }

        if (isset($config['apiKeyProvider']) && $config['apiKeyProvider'] instanceof OdooApiKeyProvider) {
            $instance->setApiKeyProvider($config['apiKeyProvider']);
        }

        if (isset($config['httpTransport']) && $config['httpTransport'] instanceof HttpTransportInterface) {
            $instance->setHttpTransport($config['httpTransport']);
        }

        if (isset($config['maxRetries']) && is_numeric($config['maxRetries'])) {
            $instance->setMaxRetries((int) $config['maxRetries']);
        }

        if (isset($config['retryBackoffBaseMs']) && is_numeric($config['retryBackoffBaseMs'])) {
            $multiplier = isset($config['retryBackoffMultiplier']) && is_numeric($config['retryBackoffMultiplier'])
                ? (float) $config['retryBackoffMultiplier']
                : $instance->getRetryBackoffMultiplier();
            $instance->setRetryBackoff((int) $config['retryBackoffBaseMs'], $multiplier);
        }

        if (isset($config['connectTimeout']) && is_numeric($config['connectTimeout'])) {
            $instance->setConnectTimeout((int) $config['connectTimeout']);
        }

        if (isset($config['timeout']) && is_numeric($config['timeout'])) {
            $instance->setTimeout((int) $config['timeout']);
        }

        if (isset($config['maxRedirects']) && is_numeric($config['maxRedirects'])) {
            $instance->setMaxRedirects((int) $config['maxRedirects']);
        }

        return $instance;
    }

    /** @param array<string, mixed> $config */
    private static function apiKeyFromArray(array $config): string
    {
        foreach (['apiKey', 'api_key', 'password'] as $key) {
            if (isset($config[$key]) && is_string($config[$key])) {
                return $config[$key];
            }
        }

        return '';
    }
}
