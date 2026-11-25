<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Adapters\OdooAdapterInterface;
use LeKoala\OdooClient\Client;
use LeKoala\OdooClient\Config;
use LeKoala\OdooClient\Enums\OdooModel;
use LeKoala\OdooClient\Events\OdooRecordCreatedEvent;
use LeKoala\OdooClient\Exceptions\OdooNetworkException;
use LeKoala\OdooClient\Exceptions\OdooValidationException;
use LeKoala\OdooClient\Http\HttpRequest;
use LeKoala\OdooClient\Http\HttpResponse;
use LeKoala\OdooClient\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

#[CoversClass(\LeKoala\OdooClient\Client::class)]
class ClientResilienceTest extends TestCase
{
    public function testReadsAreRetriedWithConfiguredBackoff(): void
    {
        $adapter = new FlakyAdapter([
            new OdooNetworkException('connection reset'),
            new OdooNetworkException('connection reset'),
            [['id' => 7]],
        ]);
        $sleeps = [];
        $client = new Client(
            (new Config('http://odoo.local', 'test_db', 'api_key'))
                ->setMaxRetries(2)
                ->setRetryBackoff(100, 2.0),
            $adapter,
            null,
            static function (int $micros) use (&$sleeps): void {
                $sleeps[] = $micros;
            }
        );

        $result = $client->searchRead('res.partner', [], ['id']);

        self::assertSame([['id' => 7]], $result);
        self::assertCount(3, $adapter->calls);
        self::assertSame([100000, 200000], $sleeps);
    }

    public function testReadsGiveUpAfterMaxRetries(): void
    {
        $adapter = new FlakyAdapter([
            new OdooNetworkException('down'),
            new OdooNetworkException('down'),
            new OdooNetworkException('down'),
        ]);
        $sleeps = [];
        $client = new Client(
            (new Config('http://odoo.local', 'test_db', 'api_key'))->setMaxRetries(1),
            $adapter,
            null,
            static function (int $micros) use (&$sleeps): void {
                $sleeps[] = $micros;
            }
        );

        $this->expectException(OdooNetworkException::class);

        try {
            $client->searchRead('res.partner', [], ['id']);
        } finally {
            self::assertCount(2, $adapter->calls);
            self::assertCount(1, $sleeps);
        }
    }

    public function testWritesAreNeverRetried(): void
    {
        $adapter = new FlakyAdapter([new OdooNetworkException('timeout after commit?')]);
        $sleeps = [];
        $client = new Client(
            (new Config('http://odoo.local', 'test_db', 'api_key'))->setMaxRetries(5),
            $adapter,
            null,
            static function (int $micros) use (&$sleeps): void {
                $sleeps[] = $micros;
            }
        );

        $this->expectException(OdooNetworkException::class);

        try {
            $client->write('res.partner', [7], ['name' => 'Alice']);
        } finally {
            self::assertCount(1, $adapter->calls);
            self::assertSame([], $sleeps);
        }
    }

    public function testWriteRetriesOnExplicitRateLimitWithRetryAfter(): void
    {
        $transport = new RecordingTransport([
            new HttpResponse(429, ['retry-after' => ['3']], 'Too Many Requests', ''),
            new HttpResponse(200, [], '[42]', ''),
        ]);
        $config = (new Config('https://odoo.example.test'))->setMaxRateLimitRetries(1);
        $sleeps = [];
        $client = new Client($config, httpTransport: $transport);
        $client->setSleeper(static function (int $micros) use (&$sleeps): void {
            $sleeps[] = $micros;
        });

        self::assertSame(42, $client->create('res.partner', ['name' => 'Alice']));
        self::assertCount(2, $transport->requests);
        self::assertSame($transport->requests[0], $transport->requests[1]);
        self::assertSame([3_000_000], $sleeps);
    }

    public function testRateLimitWithoutUsableHeaderUsesBackoffAndStops(): void
    {
        $transport = new RecordingTransport([
            new HttpResponse(429, ['Retry-After' => ['invalid']], '', ''),
            new HttpResponse(429, [], '', ''),
            new HttpResponse(429, [], 'Too Many Requests', ''),
        ]);
        $config = (new Config('https://odoo.example.test'))->setMaxRateLimitRetries(2)->setRetryBackoff(250);
        $sleeps = [];
        $client = new Client($config, httpTransport: $transport, sleeper: static function (int $micros) use (&$sleeps): void {
            $sleeps[] = $micros;
        });

        $this->expectException(OdooValidationException::class);
        $this->expectExceptionCode(429);
        try {
            $client->searchRead('res.partner');
        } finally {
            self::assertCount(3, $transport->requests);
            self::assertSame([250_000, 500_000], $sleeps);
        }
    }

    public function testRetryAfterHttpDateIsHonoured(): void
    {
        $retryAt = time() + 20;
        $transport = new RecordingTransport([
            new HttpResponse(429, ['Retry-After' => [gmdate('D, d M Y H:i:s \G\M\T', $retryAt)]], '', ''),
            new HttpResponse(200, [], '[]', ''),
        ]);
        $config = (new Config('https://odoo.example.test'))->setMaxRateLimitRetries(1);
        $sleeps = [];
        $client = new Client($config, httpTransport: $transport, sleeper: static function (int $micros) use (&$sleeps): void {
            $sleeps[] = $micros;
        });

        self::assertSame([], $client->searchRead('res.partner'));
        self::assertCount(1, $sleeps);
        self::assertGreaterThan(0, $sleeps[0]);
        self::assertEqualsWithDelta($retryAt, microtime(true) + $sleeps[0] / 1_000_000, 0.1);
    }

    public function testRateLimitRetryRequiresOptInAndDefersLongWaits(): void
    {
        foreach ([0 => '1', 3 => '60'] as $maxRetries => $retryAfter) {
            $transport = new RecordingTransport([
                new HttpResponse(429, ['Retry-After' => [$retryAfter]], 'Too Many Requests', ''),
            ]);
            $config = (new Config('https://odoo.example.test'))->setMaxRateLimitRetries($maxRetries);
            $client = new Client($config, httpTransport: $transport, sleeper: static function (): void {
                self::fail('No retry should be scheduled');
            });

            try {
                $client->searchRead('res.partner');
                self::fail('Expected an HTTP 429');
            } catch (OdooValidationException $exception) {
                self::assertSame(429, $exception->getCode());
            }
            self::assertCount(1, $transport->requests);
        }
    }

    public function testHttpClientsShareRequestSpacingIncludingRedirects(): void
    {
        $transport = new RecordingTransport([
            new HttpResponse(200, [], '[]', ''),
            new HttpResponse(302, ['Location' => ['/invoice.pdf']], '', ''),
            new HttpResponse(200, [], '%PDF', ''),
        ]);
        $config = (new Config('https://odoo.example.test'))->setMinRequestIntervalMs(1_000);
        $sleeps = [];
        $client = new Client($config, httpTransport: $transport, sleeper: static function (int $micros) use (&$sleeps): void {
            $sleeps[] = $micros;
        });

        $client->searchRead('res.partner');
        self::assertSame([], $sleeps);
        $response = $client->getHttpClient()->send(new HttpRequest('GET', 'https://odoo.example.test/portal', maxRedirects: 1));

        self::assertSame('%PDF', $response->body);
        self::assertCount(2, $sleeps);
        foreach ($sleeps as $delay) {
            self::assertGreaterThan(0, $delay);
            self::assertLessThanOrEqual(1_000_000, $delay);
        }
    }

    public function testFieldsCacheCanBeInvalidated(): void
    {
        $client = new MockClient();
        $client->mockResponse('res.partner', 'fields_get', ['name' => ['type' => 'char']]);

        self::assertSame(['name' => ['type' => 'char']], $client->fieldsOf('res.partner'));

        $client->mockResponse('res.partner', 'fields_get', ['name' => ['type' => 'char'], 'email' => ['type' => 'char']]);
        self::assertArrayNotHasKey('email', $client->fieldsOf('res.partner'));

        $client->clearFieldsCache('res.partner');
        self::assertArrayHasKey('email', $client->fieldsOf('res.partner'));

        $client->mockResponse('res.partner', 'fields_get', ['other' => ['type' => 'char']]);
        $client->clearFieldsCache();
        self::assertSame(['other' => ['type' => 'char']], $client->fieldsOf('res.partner'));
    }

    public function testListenerExceptionDoesNotFailTheOdooCall(): void
    {
        $dispatcher = new ThrowingDispatcher();
        $config = (new Config('http://odoo.local', 'test_db', 'api_key'))
            ->setEventDispatcher($dispatcher);
        $adapter = new FlakyAdapter([[42]]);
        $client = new Client($config, $adapter);

        $id = $client->create('res.partner', ['name' => 'Alice']);

        self::assertSame(42, $id);
        self::assertCount(1, $adapter->calls);
        self::assertInstanceOf(OdooRecordCreatedEvent::class, $dispatcher->seen);
    }
}

#[CoversClass(\LeKoala\OdooClient\Client::class)]
class FlakyAdapter implements OdooAdapterInterface
{
    /** @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> */
    public array $calls = [];
    /** @var array<int, mixed> */
    private array $script;

    /** @param array<int, mixed> $script Responses or throwables, in order. */
    public function __construct(array $script)
    {
        $this->script = $script;
    }

    public function authenticate(): bool
    {
        return true;
    }

    public function call(OdooModel|string $model, string $method, array $params = []): mixed
    {
        $this->calls[] = [$model instanceof OdooModel ? $model->value : $model, $method, $params];

        $next = array_shift($this->script);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}

#[CoversClass(\LeKoala\OdooClient\Client::class)]
class ThrowingDispatcher implements EventDispatcherInterface
{
    public ?object $seen = null;

    public function dispatch(object $event): object
    {
        $this->seen = $event;

        throw new \RuntimeException('listener blew up after the remote mutation');
    }
}
