<?php

namespace LeKoala\OdooClient\Tests\Addon;

use LeKoala\OdooClient\Addon\Peppol\PeppolService;
use LeKoala\OdooClient\Models\Customer;
use LeKoala\OdooClient\Models\Invoice;
use LeKoala\OdooClient\Tests\MockClient;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LeKoala\OdooClient\Addon\Peppol\PeppolService
 */
class PeppolServiceTest extends TestCase
{
    private MockClient $client;
    private PeppolService $service;

    protected function setUp(): void
    {
        $this->client = new MockClient();
        $this->service = new PeppolService($this->client);
    }

    public function testListEnabledCustomers(): void
    {
        $this->client->mockResponse('res.partner', 'search_read', [
            ['id' => 1, 'name' => 'Peppol Partner', 'peppol_eas' => '0208', 'peppol_endpoint' => '123'],
        ]);

        $customers = $this->service->listEnabledCustomers();

        self::assertContainsOnlyInstancesOf(Customer::class, $customers);
        $domain = $this->client->getLastCall()['params']['domain'] ?? [];
        self::assertContains(['peppol_eas', '!=', false], $domain);
        self::assertContains(['peppol_endpoint', '!=', false], $domain);
    }

    public function testListReadyInvoices(): void
    {
        $this->client->mockResponse('account.move', 'search_read', [
            ['id' => 1, 'name' => 'INV/2026/001', 'state' => 'posted', 'peppol_move_state' => 'to_send'],
        ]);

        $invoices = $this->service->listReadyInvoices();

        self::assertContainsOnlyInstancesOf(Invoice::class, $invoices);
        $domain = $this->client->getLastCall()['params']['domain'] ?? [];
        self::assertContains(['peppol_move_state', '=', 'to_send'], $domain);
        self::assertContains(['state', '=', 'posted'], $domain);
    }

    public function testListInvoicesByStatus(): void
    {
        $this->client->mockResponse('account.move', 'search_read', [
            ['id' => 1, 'name' => 'INV/2026/001', 'peppol_move_state' => 'error'],
        ]);

        $invoices = $this->service->listInvoicesByStatus('error');

        self::assertCount(1, $invoices);
        $domain = $this->client->getLastCall()['params']['domain'] ?? [];
        self::assertContains(['peppol_move_state', '=', 'error'], $domain);
    }

    public function testSendInvoice(): void
    {
        $this->client->mockResponse('account.move', 'action_process_edi_web_services', true);

        $this->service->sendInvoice(1);

        $lastCall = $this->client->getLastCall();
        self::assertSame('account.move', $lastCall['model']);
        self::assertSame('action_process_edi_web_services', $lastCall['method']);
        self::assertSame([1], $lastCall['params']['ids']);
    }

    public function testDeprecatedProxiesStillDelegate(): void
    {
        $this->client->mockResponse('res.partner', 'search_read', [
            ['id' => 1, 'name' => 'Peppol Partner', 'peppol_eas' => '0208', 'peppol_endpoint' => '123'],
        ]);
        $this->client->mockResponse('account.move', 'search_read', [
            ['id' => 1, 'name' => 'INV/2026/001', 'state' => 'posted', 'peppol_move_state' => 'to_send'],
        ]);
        $this->client->mockResponse('account.move', 'action_process_edi_web_services', true);

        $customers = (new \LeKoala\OdooClient\Services\CustomerService($this->client))->listPeppolEnabled();
        $invoices = (new \LeKoala\OdooClient\Services\InvoiceService($this->client))->listPeppolReady();
        (new \LeKoala\OdooClient\Services\InvoiceService($this->client))->sendViaPeppol(1);

        self::assertCount(1, $customers);
        self::assertCount(1, $invoices);
        self::assertSame('action_process_edi_web_services', $this->client->getLastCall()['method']);
    }
}
