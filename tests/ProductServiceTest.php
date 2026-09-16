<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Services\ProductService;
use LeKoala\OdooClient\Models\Tax;
use PHPUnit\Framework\TestCase;
use LeKoala\OdooClient\QueryOptions;

/**
 * @covers \LeKoala\OdooClient\Services\ProductService
 */
class ProductServiceTest extends TestCase
{
    private MockClient $client;
    private ProductService $service;

    protected function setUp(): void
    {
        $this->client = new MockClient();
        $this->service = new ProductService($this->client);
    }

    public function testListTaxes(): void
    {
        $this->client->mockResponse('account.tax', 'search_read', [
            ['id' => 1, 'name' => '21% VAT', 'amount' => 21.0, 'type_tax_use' => 'sale', 'amount_type' => 'percent'],
        ]);

        $taxes = $this->service->listTaxes();

        self::assertCount(1, $taxes);
        self::assertEquals('21% VAT', $taxes[0]->name);
        self::assertEquals(21.0, $taxes[0]->amount);

        $lastCall = $this->client->getLastCall();
        /** @var array<int, mixed> $domain */
        $domain = $lastCall['params']['domain'] ?? [];
        self::assertContains(['type_tax_use', '=', 'sale'], $domain);
        self::assertSame(0, $lastCall['params']['limit']);
    }

    public function testProductsAndTaxesAcceptQueryOptions(): void
    {
        $this->client->mockResponse('product.product', 'search_read', []);
        $this->service->list([], new QueryOptions(limit: 12, offset: 4, order: 'name asc'));
        $productParams = $this->client->getLastCall()['params'];
        self::assertSame(12, $productParams['limit']);
        self::assertSame(4, $productParams['offset']);

        $this->client->mockResponse('account.tax', 'search_read', []);
        $this->service->listTaxes('sale', new QueryOptions());
        self::assertSame(0, $this->client->getLastCall()['params']['limit']);
    }
}
