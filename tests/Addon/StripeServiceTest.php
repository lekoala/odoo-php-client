<?php

namespace LeKoala\OdooClient\Tests\Addon;

use LeKoala\OdooClient\Addon\Stripe\AmbiguousStripeProviderException;
use LeKoala\OdooClient\Addon\Stripe\NoStripeProviderException;
use LeKoala\OdooClient\Addon\Stripe\StripeService;
use LeKoala\OdooClient\Tests\MockClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\LeKoala\OdooClient\Addon\Stripe\StripeService::class)]
class StripeServiceTest extends TestCase
{
    private MockClient $client;
    private StripeService $service;

    protected function setUp(): void
    {
        $this->client = new MockClient();
        $this->service = new StripeService($this->client);
    }

    public function testSingleActiveProviderResolves(): void
    {
        $this->client->mockResponse('payment.provider', 'search_read', [[
            'id' => 7,
            'name' => 'Stripe',
            'code' => 'stripe',
            'state' => 'enabled',
            'company_id' => [3, 'My Company'],
            'is_published' => true,
        ]]);

        self::assertSame(7, $this->service->requireStripeProviderId());
        // Deprecated accessor stays compatible for the single-provider target.
        self::assertSame(7, $this->service->getStripeProviderId());
    }

    public function testNoProviderThrowsOnRequireAndReturnsNullOnLegacyAccessor(): void
    {
        $this->client->mockResponse('payment.provider', 'search_read', []);

        $this->expectException(NoStripeProviderException::class);

        try {
            $this->service->requireStripeProviderId();
        } finally {
            self::assertNull($this->service->getStripeProviderId());
        }
    }

    public function testDisabledProvidersAreExcludedByDefault(): void
    {
        $this->client->mockResponse('payment.provider', 'search_read', [
            ['id' => 7, 'name' => 'Stripe', 'state' => 'enabled', 'company_id' => [3, 'MC']],
            ['id' => 8, 'name' => 'Stripe old', 'state' => 'disabled', 'company_id' => [3, 'MC']],
        ]);

        self::assertSame(7, $this->service->requireStripeProviderId());
    }

    public function testSeveralActiveProvidersAreAmbiguous(): void
    {
        $this->client->mockResponse('payment.provider', 'search_read', [
            ['id' => 7, 'name' => 'Stripe BE', 'state' => 'enabled', 'company_id' => [3, 'BE']],
            ['id' => 9, 'name' => 'Stripe NL', 'state' => 'enabled', 'company_id' => [4, 'NL']],
        ]);

        try {
            $this->service->requireStripeProviderId();
            self::fail('Several providers must not resolve silently');
        } catch (AmbiguousStripeProviderException $e) {
            self::assertStringContainsString('7', $e->getMessage());
            self::assertStringContainsString('9', $e->getMessage());
        }

        // Explicit company disambiguates.
        self::assertSame(9, $this->service->requireStripeProviderId(companyId: 4));
    }

    public function testTestAndLiveProvidersRequireAnExplicitState(): void
    {
        $this->client->mockResponse('payment.provider', 'search_read', [
            ['id' => 7, 'name' => 'Stripe', 'state' => 'test', 'company_id' => [3, 'MC']],
            ['id' => 9, 'name' => 'Stripe', 'state' => 'enabled', 'company_id' => [3, 'MC']],
        ]);

        $this->expectException(AmbiguousStripeProviderException::class);

        try {
            $this->service->requireStripeProviderId(companyId: 3);
        } finally {
            self::assertSame(7, $this->service->requireStripeProviderId(companyId: 3, expectedState: 'test'));
            self::assertSame(9, $this->service->requireStripeProviderId(companyId: 3, expectedState: 'enabled'));
        }
    }

    public function testImportStripeTokenStillWorksThroughTheAddon(): void
    {
        $this->client->mockResponse('payment.method', 'search_read', [['id' => 69]]);
        $this->client->mockResponse('payment.token', 'create', 101);
        $this->client->mockResponse('payment.token', 'read', [[
            'id' => 101,
            'provider_ref' => 'cus_test',
            'stripe_payment_method' => 'pm_test',
        ]]);

        $id = $this->service->importStripeToken(42, 'cus_test', 'pm_test', '.... 4242', 7);

        self::assertSame(101, $id);
    }
}
