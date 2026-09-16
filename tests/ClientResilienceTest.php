<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Adapters\OdooAdapterInterface;
use LeKoala\OdooClient\Client;
use LeKoala\OdooClient\Config;
use LeKoala\OdooClient\Enums\OdooModel;
use LeKoala\OdooClient\Events\OdooRecordCreatedEvent;
use LeKoala\OdooClient\Exceptions\OdooNetworkException;
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
