<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Services\CustomerService;
use LeKoala\OdooClient\Models\Customer;
use LeKoala\OdooClient\Inputs\CustomerInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LeKoala\OdooClient\QueryOptions;

#[CoversClass(\LeKoala\OdooClient\Services\CustomerService::class)]
class CustomerServiceTest extends TestCase
{
    private MockClient $client;
    private CustomerService $service;

    protected function setUp(): void
    {
        $this->client = new MockClient();
        $this->service = new CustomerService($this->client);
    }

    /**
     * @return array{model: string, method: string, params: array<string, mixed>}
     */
    private function getLastCall(): array
    {
        return $this->client->getLastCall();
    }

    public function testListReturnsCustomers(): void
    {
        $this->client->mockResponse('res.partner', 'search_read', [
            ['id' => 1, 'name' => 'Acme Corp', 'email' => 'info@acme.com', 'is_company' => true],
            ['id' => 2, 'name' => 'John Doe', 'email' => 'john@example.com', 'is_company' => false],
        ]);

        $customers = $this->service->list();

        self::assertCount(2, $customers);
        self::assertInstanceOf(Customer::class, $customers[0]);
        self::assertEquals('Acme Corp', $customers[0]->name);
        self::assertTrue($customers[0]->isCompany);

        $lastCall = $this->getLastCall();
        self::assertEquals([['customer_rank', '>', 0]], $lastCall['params']['domain']);
    }

    public function testGetReturnsCustomer(): void
    {
        $this->client->mockResponse('res.partner', 'read', [
            ['id' => 42, 'name' => 'Douglas Adams', 'email' => 'douglas@hitchhiker.com'],
        ]);

        $customer = $this->service->get(42);

        self::assertInstanceOf(Customer::class, $customer);
        self::assertEquals('Douglas Adams', $customer->name);
        self::assertEquals(42, $customer->id);
    }

    public function testCreateCustomer(): void
    {
        $this->client->mockResponse('res.partner', 'create', 99);

        $input = new CustomerInput(name: 'New Corp', isCompany: true, email: 'contact@newcorp.com');
        $id = $this->service->create($input);

        self::assertEquals(99, $id);

        $lastCall = $this->getLastCall();
        $expectedPayload = [
            'name' => 'New Corp',
            'is_company' => true,
            'email' => 'contact@newcorp.com',
        ];
        self::assertEquals($expectedPayload, $lastCall['params']['vals_list'][0]);
    }

    public function testUpdateCustomer(): void
    {
        $this->client->mockResponse('res.partner', 'write', true);

        $input = new CustomerInput(name: 'Updated Corp');
        $result = $this->service->update(99, $input);

        self::assertTrue($result);

        $lastCall = $this->getLastCall();
        self::assertEquals([99], $lastCall['params']['ids']);
        $expectedPayload = [
            'name' => 'Updated Corp',
            'is_company' => false,
        ];
        self::assertEquals($expectedPayload, $lastCall['params']['vals']);
    }

    public function testCreateDropsFieldsMissingFromTheInstanceSchema(): void
    {
        $this->client->mockResponse('res.partner', 'fields_get', [
            'name' => ['type' => 'char'],
            'is_company' => ['type' => 'boolean'],
            'email' => ['type' => 'char'],
            'phone' => ['type' => 'char'],
        ]);
        $this->client->mockResponse('res.partner', 'create', 7);

        $input = new CustomerInput(
            name: 'New Corp',
            email: 'contact@newcorp.com',
            phone: '+3212345678',
            mobile: '+3245678901',
        );
        $this->service->create($input);

        $values = $this->client->getLastCall()['params']['vals_list'][0];
        self::assertArrayNotHasKey('mobile', $values);
        self::assertSame('+3212345678', $values['phone']);
        self::assertSame('contact@newcorp.com', $values['email']);
    }

    public function testPartialUpdateKeepsExplicitFalseButDropsUnknownFields(): void
    {
        $this->client->mockResponse('res.partner', 'fields_get', [
            'active' => ['type' => 'boolean'],
        ]);
        $this->client->mockResponse('res.partner', 'write', true);

        $this->service->updateFields(99, ['active' => false, 'mobile' => '+3245678901']);

        $values = $this->client->getLastCall()['params']['vals'];
        self::assertSame(['active' => false], $values);
    }

    public function testListAcceptsUnboundedDetailedQueryOptions(): void
    {
        $this->client->mockResponse('res.partner', 'search_read', []);

        $this->service->list([], new QueryOptions(
            offset: 20,
            order: 'name asc',
            fields: ['id', 'name'],
            context: ['lang' => 'fr_BE'],
        ));

        $params = $this->client->getLastCall()['params'];
        self::assertArrayNotHasKey('limit', $params);
        self::assertSame(20, $params['offset']);
        self::assertSame('name asc', $params['order']);
        self::assertSame(['id', 'name'], $params['fields']);
        self::assertSame(['lang' => 'fr_BE'], $params['context']);
    }

    public function testIterateReturnsTypedCustomersAcrossStablePages(): void
    {
        $this->client->mockResponseSequence('res.partner', 'search_read', [
            [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
            [],
        ]);

        $customers = iterator_to_array($this->service->iterate(
            options: new QueryOptions(fields: ['id', 'name'], batchSize: 2),
        ));

        self::assertCount(2, $customers);
        self::assertContainsOnlyInstancesOf(Customer::class, $customers);
        $searchCalls = array_values(array_filter(
            $this->client->getCallLog(),
            static fn(array $call): bool => $call['method'] === 'search_read',
        ));
        self::assertCount(2, $searchCalls);
        self::assertSame('id asc', $searchCalls[0]['params']['order']);
        self::assertSame(2, $searchCalls[1]['params']['offset']);
    }

    public function testUpdateFieldsDoesNotAddCreationDefaults(): void
    {
        $this->client->mockResponse('res.partner', 'write', true);

        self::assertTrue($this->service->updateFields(99, ['phone' => '+3212345678']));

        $lastCall = $this->getLastCall();
        self::assertSame([99], $lastCall['params']['ids']);
        self::assertSame(['phone' => '+3212345678'], $lastCall['params']['vals']);
    }

    public function testSearchFiltersCorrectly(): void
    {
        $this->client->mockResponse('res.partner', 'search_read', [
            ['id' => 5, 'name' => 'Agrolait'],
        ]);

        $results = $this->service->search('Agro');

        self::assertCount(1, $results);
        self::assertEquals('Agrolait', $results[0]->name);

        $lastCall = $this->getLastCall();
        /** @var array<int, mixed> $domain */
        $domain = $lastCall['params']['domain'];

        /** @var array<int, array<int, mixed>> $arrayDomainItems */
        $arrayDomainItems = array_filter($domain, 'is_array');
        self::assertContains('ilike', array_column($arrayDomainItems, 1));
    }

    public function testFindByEmail(): void
    {
        $this->client->mockResponse('res.partner', 'search_read', [
            ['id' => 10, 'name' => 'Email Test', 'email' => 'test@example.com'],
        ]);

        $results = $this->service->findByEmail('test@example.com');

        self::assertCount(1, $results);
        $lastCall = $this->getLastCall();
        /** @var iterable<mixed> $domain */
        $domain = $lastCall['params']['domain'];
        self::assertContains(['email', '=', 'test@example.com'], $domain);
    }

    public function testGetByCountry(): void
    {
        $this->client->mockResponse('res.country', 'search_read', [['id' => 21]]);

        $this->client->mockResponse('res.partner', 'search_read', [
            ['id' => 1, 'name' => 'Belgian Customer', 'country_id' => [21, 'Belgium']],
        ]);

        $customers = $this->service->getByCountry('BE');

        self::assertCount(1, $customers);
        self::assertEquals('Belgium', $customers[0]->countryName);
    }

    public function testListPeppolEnabled(): void
    {
        $this->client->mockResponse('res.partner', 'search_read', [
            ['id' => 1, 'name' => 'Peppol Partner', 'peppol_eas' => '0208', 'peppol_endpoint' => '123'],
        ]);

        $customers = $this->service->listPeppolEnabled();

        self::assertCount(1, $customers);
        $lastCall = $this->getLastCall();
        /** @var iterable<mixed> $domain */
        $domain = $lastCall['params']['domain'];

        self::assertContains(['peppol_eas', '!=', false], $domain);
        self::assertContains(['peppol_endpoint', '!=', false], $domain);
    }

    public function testFindByNamePartial(): void
    {
        $this->client->mockResponse('res.partner', 'search_read', [
            ['id' => 1, 'name' => 'Acme Corp'],
        ]);

        $results = $this->service->findByName('Acme');

        self::assertCount(1, $results);
        $lastCall = $this->getLastCall();
        /** @var iterable<mixed> $domain */
        $domain = $lastCall['params']['domain'];
        self::assertContains(['name', 'ilike', 'Acme'], $domain);
    }

    public function testFindByNameExact(): void
    {
        $this->client->mockResponse('res.partner', 'search_read', [
            ['id' => 1, 'name' => 'Acme Corp'],
        ]);

        $results = $this->service->findByName('Acme Corp', true);

        self::assertCount(1, $results);
        $lastCall = $this->getLastCall();
        /** @var iterable<mixed> $domain */
        $domain = $lastCall['params']['domain'];
        self::assertContains(['name', '=', 'Acme Corp'], $domain);
    }

    public function testGetPaymentTokens(): void
    {
        $this->client->mockResponse('payment.token', 'search_read', [
            ['id' => 1, 'name' => '•••• 4242', 'partner_id' => [42, 'Douglas Adams']],
        ]);

        $tokens = $this->service->getPaymentTokens(42);

        self::assertCount(1, $tokens);
        self::assertEquals('•••• 4242', $tokens[0]->name);
        self::assertEquals(42, $tokens[0]->partnerId);
        self::assertArrayNotHasKey('limit', $this->client->getLastCall()['params']);
    }
}
