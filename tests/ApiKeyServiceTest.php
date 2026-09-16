<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\OdooException;
use LeKoala\OdooClient\Services\ApiKeyService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiKeyService::class)]
class ApiKeyServiceTest extends TestCase
{
    private MockClient $client;
    private ApiKeyService $service;

    protected function setUp(): void
    {
        $this->client = new MockClient();
        $this->service = new ApiKeyService($this->client);
    }

    public function testListKeysParsesOdoosRows(): void
    {
        $this->client->mockResponse('res.users.apikeys', 'search_read', [
            ['id' => 9, 'name' => 'test', 'scope' => false, 'expiration_date' => '2026-09-23'],
            ['id' => 4, 'name' => 'rpc', 'scope' => 'rpc', 'expiration_date' => false],
        ]);

        $keys = $this->service->listKeys();

        self::assertCount(2, $keys);
        self::assertNull($keys[0]->scope);
        self::assertTrue($keys[0]->isUnscoped());
        self::assertSame('2026-09-23', $keys[0]->expirationDate);
        self::assertSame('rpc', $keys[1]->scope);
        self::assertNull($keys[1]->expirationDate);
        self::assertNull($keys[1]->daysUntilExpiry());
    }

    public function testDaysUntilExpiryGoesNegativeOnceLapsed(): void
    {
        $this->client->mockResponse('res.users.apikeys', 'search_read', [
            ['id' => 9, 'name' => 'test', 'scope' => false, 'expiration_date' => '2026-01-10'],
        ]);

        $key = $this->service->listKeys()[0];

        self::assertSame(-20, $key->daysUntilExpiry(strtotime('2026-01-30 00:00:00')));
    }

    public function testFindByNameQueriesOdooByExactName(): void
    {
        $this->client->mockResponse('res.users.apikeys', 'search_read', static function (array $params): array {
            $name = $params['domain'][0][2] ?? null;

            return $name === 'shop-live' ? [['id' => 8, 'name' => 'shop-live']] : [];
        });

        self::assertSame(8, $this->service->findByName('shop-live')?->id);
        self::assertNull($this->service->findByName('shop'));

        $call = $this->client->getLastCall();
        self::assertContains(['name', '=', 'shop'], $call['params']['domain']);
        self::assertSame(1, $call['params']['limit']);
    }

    public function testGenerateSendsEverythingAsNamedParameters(): void
    {
        $this->client->setApiKey('current-key-value');
        $this->client->mockResponse('res.users.apikeys', 'generate', 'brand-new-key');

        $key = $this->service->generate('rotation-1', '2026-11-15');

        self::assertSame('brand-new-key', $key);

        $call = $this->client->getLastCall();
        self::assertSame('res.users.apikeys', $call['model']);
        self::assertSame('generate', $call['method']);
        self::assertSame('current-key-value', $call['params']['key']);
        self::assertSame('rotation-1', $call['params']['name']);
        self::assertSame('2026-11-15', $call['params']['expiration_date']);
        self::assertNull($call['params']['scope']);
    }

    #[DataProvider('generateResponseShapes')]
    public function testGenerateReadsTheKeyOutOfAnyPlausibleShape(mixed $response): void
    {
        $this->client->mockResponse('res.users.apikeys', 'generate', $response);

        self::assertSame('brand-new-key', $this->service->generate('rotation-1'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function generateResponseShapes(): array
    {
        return [
            'bare string' => ['brand-new-key'],
            'result envelope' => [['result' => 'brand-new-key']],
            'key envelope' => [['key' => 'brand-new-key']],
            'single unnamed value' => [['anything' => 'brand-new-key']],
            'padded' => ["  brand-new-key\n"],
        ];
    }

    public function testGenerateWithNoReadableKeyTellsTheCallerToLookNotRetry(): void
    {
        $this->client->mockResponse('res.users.apikeys', 'generate', ['unexpected' => ['nested']]);

        try {
            $this->service->generate('rotation-1');
            self::fail('An unreadable generate() answer must raise.');
        } catch (OdooException $e) {
            self::assertStringContainsString('Assume the key WAS created', $e->getMessage());
            self::assertStringContainsString('rotation-1', $e->getMessage());
            self::assertStringContainsString('Do not retry', $e->getMessage());
        }
    }

    public function testGenerateIsCalledExactlyOnceEvenWhenItAnswersUnusably(): void
    {
        $this->client->mockResponse('res.users.apikeys', 'generate', false);

        try {
            $this->service->generate('rotation-1');
        } catch (OdooException) {
        }

        $generateCalls = array_filter(
            $this->client->getCallLog(),
            static fn(array $call): bool => $call['method'] === 'generate'
        );

        self::assertCount(1, $generateCalls);
    }

    public function testRevokeUsesTheDocumentedKeyParameter(): void
    {
        $this->client->mockResponse('res.users.apikeys', 'revoke', true);

        self::assertTrue($this->service->revoke('old-key-value'));

        $call = $this->client->getLastCall();
        self::assertSame('revoke', $call['method']);
        self::assertSame('old-key-value', $call['params']['key']);
    }

    public function testRevokeRefusesAnEmptyKey(): void
    {
        $this->expectException(OdooException::class);
        $this->expectExceptionMessage('empty API key');

        $this->service->revoke('');
    }

    public function testProgrammaticManagementIsNullWhenNobodyHasDecided(): void
    {
        $this->client->mockResponse('ir.config_parameter', 'search_read', []);

        self::assertNull($this->service->programmaticManagementEnabled());
    }

    #[DataProvider('truthyParameterValues')]
    public function testProgrammaticManagementReadsOdoosTruthyStrings(string $value, bool $expected): void
    {
        $this->client->mockResponse('ir.config_parameter', 'search_read', [
            ['key' => 'base.enable_programmatic_api_keys', 'value' => $value],
        ]);

        self::assertSame($expected, $this->service->programmaticManagementEnabled());
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function truthyParameterValues(): array
    {
        return [
            'True' => ['True', true],
            'true' => ['true', true],
            'one' => ['1', true],
            'padded' => [" True \n", true],
            'False' => ['False', false],
            'zero' => ['0', false],
            'nonsense' => ['maybe', false],
        ];
    }

    public function testKeyLimitFallsBackToOdoosDefault(): void
    {
        $this->client->mockResponse('ir.config_parameter', 'search_read', []);

        self::assertSame(ApiKeyService::DEFAULT_KEY_LIMIT, $this->service->programmaticKeyLimit());
    }

    public function testKeyLimitReadsTheConfiguredValue(): void
    {
        $this->client->mockResponse('ir.config_parameter', 'search_read', [
            ['key' => 'base.programmatic_api_keys_limit', 'value' => '25'],
        ]);

        self::assertSame(25, $this->service->programmaticKeyLimit());
    }
}
