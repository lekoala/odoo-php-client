<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Client;
use LeKoala\OdooClient\Config;
use LeKoala\OdooClient\Adapters\OdooAdapterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LeKoala\OdooClient\Enums\OdooModel;
use LeKoala\OdooClient\QueryOptions;

#[CoversClass(\LeKoala\OdooClient\Client::class)]
class ClientTest extends TestCase
{
    private MockClient $client;

    protected function setUp(): void
    {
        $this->client = new MockClient();
    }

    public function testVersion(): void
    {
        $this->client->mockResponse('common', 'version', ['server_version' => '17.0']);

        $version = $this->client->version();

        self::assertEquals('17.0', $version['server_version']);
    }

    public function testFieldsOf(): void
    {
        $this->client->mockResponse('res.partner', 'fields_get', ['name' => ['type' => 'char']]);

        $fields = $this->client->fieldsOf('res.partner');

        /** @var array<string, mixed> $nameField */
        $nameField = $fields['name'] ?? [];
        self::assertEquals('char', $nameField['type'] ?? null);
    }

    private function clientWithSpyAdapter(SpyOdooAdapter $spy): Client
    {
        return new Client(
            new Config('http://odoo.local', 'test_db', 'api_key'),
            $spy
        );
    }

    public function testCallHandsTheNamedParametersToTheAdapterUntouched(): void
    {
        $spy = new SpyOdooAdapter();
        $params = ['ids' => [9], 'is_modify' => false, 'context' => ['lang' => 'fr_FR']];

        $this->clientWithSpyAdapter($spy)->call('account.move.reversal', 'reverse_moves', $params);

        self::assertSame([['account.move.reversal', 'reverse_moves', $params]], $spy->calls);
    }

    public function testExecuteReachesTheAdapterThroughCall(): void
    {
        $spy = new SpyOdooAdapter();

        $this->clientWithSpyAdapter($spy)->execute('account.move', 'action_post', [[7]]);

        self::assertSame([['account.move', 'action_post', ['ids' => [7]]]], $spy->calls);
    }

    public function testCreateReturnsTheSingleIdentifierFromOdoo(): void
    {
        $spy = new SpyOdooAdapter();
        $spy->response = [42];

        $id = $this->clientWithSpyAdapter($spy)->create('res.partner', ['name' => 'Alice']);

        self::assertSame(42, $id);
        self::assertSame(
            [['res.partner', 'create', ['vals_list' => [['name' => 'Alice']]]]],
            $spy->calls
        );
    }

    public function testCreateManyPreservesEveryIdentifier(): void
    {
        $spy = new SpyOdooAdapter();
        $spy->response = [42, 43];
        $values = [['name' => 'Alice'], ['name' => 'Bob']];

        $ids = $this->clientWithSpyAdapter($spy)->createMany('res.partner', $values);

        self::assertSame([42, 43], $ids);
        self::assertSame(
            [['res.partner', 'create', ['vals_list' => $values]]],
            $spy->calls
        );
    }

    public function testReadPassesContextAsANamedParameter(): void
    {
        $spy = new SpyOdooAdapter();
        $spy->response = [['id' => 7, 'name' => 'Alice']];

        $record = $this->clientWithSpyAdapter($spy)->find(
            'res.partner',
            7,
            ['name'],
            ['allowed_company_ids' => [2]]
        );

        self::assertSame(['id' => 7, 'name' => 'Alice'], $record);
        self::assertSame([
            'ids' => [7],
            'fields' => ['name'],
            'context' => ['allowed_company_ids' => [2]],
        ], $spy->calls[0][2]);
    }

    public function testSearchCountAndReadGroupUseNamedParameters(): void
    {
        $spy = new SpyOdooAdapter();
        $client = $this->clientWithSpyAdapter($spy);
        $spy->response = 12;
        self::assertSame(12, $client->searchCount('res.partner', [['active', '=', true]]));

        $spy->response = [['country_id' => [21, 'Belgium'], 'country_id_count' => 12]];
        $groups = $client->readGroup(
            'res.partner',
            [['active', '=', true]],
            ['country_id'],
            ['country_id']
        );

        self::assertCount(1, $groups);
        self::assertSame('search_count', $spy->calls[0][1]);
        self::assertSame('read_group', $spy->calls[1][1]);
        self::assertSame(['country_id'], $spy->calls[1][2]['groupby']);
    }

    public function testIterateSearchReadUsesStableBoundedPages(): void
    {
        $this->client->mockResponseSequence('res.partner', 'search_read', [
            [['id' => 6], ['id' => 7]],
            [['id' => 8]],
        ]);

        $records = iterator_to_array($this->client->iterateSearchRead(
            'res.partner',
            [['active', '=', true]],
            ['id'],
            new QueryOptions(
                limit: 3,
                offset: 5,
                order: 'name asc',
                context: ['allowed_company_ids' => [2]],
                batchSize: 2,
            ),
        ));

        self::assertSame([6, 7, 8], array_column($records, 'id'));
        $calls = $this->client->getCallLog();
        self::assertCount(2, $calls);
        self::assertSame(5, $calls[0]['params']['offset']);
        self::assertSame(2, $calls[0]['params']['limit']);
        self::assertSame(7, $calls[1]['params']['offset']);
        self::assertSame(1, $calls[1]['params']['limit']);
        self::assertSame('name asc, id asc', $calls[0]['params']['order']);
        self::assertSame(['allowed_company_ids' => [2]], $calls[0]['params']['context']);
    }

    public function testSearchReadOnlySendsProvidedOptions(): void
    {
        $spy = new SpyOdooAdapter();
        $spy->response = [];

        $this->clientWithSpyAdapter($spy)->searchRead('res.partner', [['active', '=', true]]);

        self::assertSame(['domain' => [['active', '=', true]]], $spy->calls[0][2]);
    }

    public function testSearchReadKeepsExplicitOptions(): void
    {
        $spy = new SpyOdooAdapter();
        $spy->response = [];

        $this->clientWithSpyAdapter($spy)->searchRead(
            'res.partner',
            [],
            ['id', 'name'],
            25,
            10,
            'name asc',
            ['lang' => 'fr_BE'],
        );

        self::assertSame([
            'domain' => [],
            'fields' => ['id', 'name'],
            'limit' => 25,
            'offset' => 10,
            'order' => 'name asc',
            'context' => ['lang' => 'fr_BE'],
        ], $spy->calls[0][2]);
    }

    public function testDefaultContextFillsGapsUnderExplicitContext(): void
    {
        $spy = new SpyOdooAdapter();
        $spy->response = [];
        $config = (new Config('http://odoo.local', 'test_db', 'api_key'))
            ->setDefaultContext(['lang' => 'fr_BE', 'tz' => 'Europe/Brussels']);

        (new Client($config, $spy))->searchRead(
            'res.partner',
            [],
            [],
            0,
            0,
            null,
            ['lang' => 'en_US'],
        );

        self::assertSame(
            ['lang' => 'en_US', 'tz' => 'Europe/Brussels'],
            $spy->calls[0][2]['context'],
        );
    }

    public function testDefaultContextIsAppliedToWrites(): void
    {
        $spy = new SpyOdooAdapter();
        $spy->response = [5];
        $config = (new Config('http://odoo.local', 'test_db', 'api_key'))
            ->setDefaultContext(['allowed_company_ids' => [2]]);

        (new Client($config, $spy))->create('res.partner', ['name' => 'Alice']);

        self::assertSame([
            'vals_list' => [['name' => 'Alice']],
            'context' => ['allowed_company_ids' => [2]],
        ], $spy->calls[0][2]);
    }

    public function testSensitiveValuesInPositionalDomainsAreRedacted(): void
    {
        $method = new \ReflectionMethod(Client::class, 'redactForLog');
        $method->setAccessible(true);

        $redacted = $method->invoke(null, ['domain' => [['email', '=', 'alice@example.com']]]);

        self::assertSame('<string:17>', $redacted['domain'][0][2]);
    }
}

#[CoversClass(\LeKoala\OdooClient\Client::class)]
class SpyOdooAdapter implements OdooAdapterInterface
{
    public mixed $response = true;
    /** @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> */
    public array $calls = [];

    public function authenticate(): bool
    {
        return true;
    }

    public function call(OdooModel|string $model, string $method, array $params = []): mixed
    {
        $this->calls[] = [$model instanceof OdooModel ? $model->value : $model, $method, $params];

        return $this->response;
    }
}
