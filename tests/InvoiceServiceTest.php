<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Services\InvoiceService;
use LeKoala\OdooClient\Models\Invoice;
use LeKoala\OdooClient\Inputs\InvoiceInput;
use LeKoala\OdooClient\Inputs\InvoiceLineInput;
use LeKoala\OdooClient\Http\HttpResponse;
use LeKoala\OdooClient\Tests\Support\RecordingTransport;
use LeKoala\OdooClient\QueryOptions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LeKoala\OdooClient\Services\InvoiceService
 */
class InvoiceServiceTest extends TestCase
{
    private MockClient $client;
    private InvoiceService $service;

    protected function setUp(): void
    {
        $this->client = new MockClient();
        $this->service = new InvoiceService($this->client);
    }

    public function testListReturnsInvoices(): void
    {
        $this->client->mockResponse('account.move', 'search_read', [
            [
                'id' => 1,
                'name' => 'INV/2026/001',
                'partner_id' => [10, 'Customer A'],
                'state' => 'posted',
                'move_type' => 'out_invoice',
                'amount_total' => 100.0,
                'amount_residual' => 100.0,
                'payment_state' => 'not_paid',
            ],
        ]);

        $invoices = $this->service->list();

        self::assertCount(1, $invoices);
        self::assertInstanceOf(Invoice::class, $invoices[0]);
        self::assertEquals('INV/2026/001', $invoices[0]->name);
        self::assertEquals(100.0, $invoices[0]->amountTotal);
        self::assertSame(0, $this->client->getLastCall()['params']['limit']);
    }

    public function testGetReturnsInvoice(): void
    {
        $this->client->mockResponse('account.move', 'read', [
            [
                'id' => 1,
                'name' => 'INV/2026/001',
                'partner_id' => [10, 'Customer A'],
                'state' => 'posted',
                'move_type' => 'out_invoice',
                'amount_total' => 100.0,
                'amount_residual' => 0.0,
                'payment_state' => 'paid',
            ],
        ]);

        $invoice = $this->service->get(1);

        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertEquals('paid', $invoice->paymentState);
    }

    public function testCreateReturnsId(): void
    {
        $this->client->mockResponse('account.move', 'create', 99);

        $input = new InvoiceInput(
            partnerId: 10,
            lines: [
                new InvoiceLineInput(productId: 1, quantity: 2, name: 'SMS Pack')
            ]
        );
        $id = $this->service->create($input);

        self::assertEquals(99, $id);
        $lastCall = $this->client->getLastCall();
        self::assertEquals('create', $lastCall['method']);
        /** @var array<string, mixed> $values */
        $values = $lastCall['params']['vals_list'][0] ?? [];
        self::assertEquals(10, $values['partner_id'] ?? null);
    }

    public function testCreateCreditNote(): void
    {
        $this->client->mockResponse('account.move', 'create', 101);

        $input = new InvoiceInput(
            partnerId: 10,
            lines: []
        );
        $id = $this->service->createCreditNote($input);

        self::assertEquals(101, $id);

        $lastCall = $this->client->getLastCall();
        self::assertEquals('create', $lastCall['method']);
        /** @var array<string, mixed> $values */
        $values = $lastCall['params']['vals_list'][0] ?? [];
        self::assertEquals('out_refund', $values['move_type'] ?? null);
    }

    public function testReverseInvoiceReturnsResId(): void
    {
        // Mock step 1: Wizard creation
        $this->client->mockResponse('account.move.reversal', 'create', 999);

        // Mock step 2: reverse_moves returns an action with res_id
        $this->client->mockResponse('account.move.reversal', 'reverse_moves', [
            'res_model' => 'account.move',
            'res_id' => 102
        ]);

        $newId = $this->service->reverseInvoice(1, 'Refund request');

        self::assertEquals(102, $newId);

        $log = $this->client->getCallLog();
        $createCall = $log[count($log) - 2];
        $reverseCall = $log[count($log) - 1];

        // Verify wizard created with reason
        self::assertEquals('create', $createCall['method']);
        /** @var array<string, mixed> $createValues */
        $createValues = $createCall['params']['vals_list'][0] ?? [];
        self::assertEquals('Refund request', $createValues['reason'] ?? null);
        /** @var array<string, mixed> $createContext */
        $createContext = $createCall['params']['context'] ?? [];
        self::assertEquals([1], $createContext['active_ids'] ?? null);

        // Verify reverse_moves called
        self::assertEquals('reverse_moves', $reverseCall['method']);
        self::assertEquals([999], $reverseCall['params']['ids']);
    }

    public function testReverseInvoiceReturnsFromDomain(): void
    {
        $this->client->mockResponse('account.move.reversal', 'create', 999);

        // Mock step 2: reverse_moves returns an action with domain instead of res_id
        $this->client->mockResponse('account.move.reversal', 'reverse_moves', [
            'res_model' => 'account.move',
            'domain' => [['id', 'in', [105]]]
        ]);

        $newId = $this->service->reverseInvoice(2);

        self::assertEquals(105, $newId);
    }

    public function testConfirmCallsActionPostAndVerifiesState(): void
    {
        $this->client->mockResponse('account.move', 'action_post', true);
        $this->client->mockResponse('account.move', 'read', [
            [
                'id' => 1,
                'state' => 'posted',
                'name' => 'INV/2026/001',
            ],
        ]);

        $result = $this->service->confirm(1);

        self::assertTrue($result);

        $log = $this->client->getCallLog();
        $actionPostCall = null;
        foreach ($log as $call) {
            if ($call['method'] === 'action_post') {
                $actionPostCall = $call;
            }
        }
        self::assertNotNull($actionPostCall, 'action_post should be in call log');
        self::assertEquals([1], $actionPostCall['params']['ids']);
    }

    public function testConfirmWithoutStateVerification(): void
    {
        $this->client->mockResponse('account.move', 'action_post', true);

        $result = $this->service->confirm(1, false);

        self::assertTrue($result);
        $lastCall = $this->client->getLastCall();
        self::assertEquals('action_post', $lastCall['method']);
    }

    public function testGetOutstandingFiltersCorrectly(): void
    {
        $this->client->mockResponse('account.move', 'search_read', [
            [
                'id' => 1,
                'name' => 'INV/2026/001',
                'partner_id' => [10, 'Customer A'],
                'state' => 'posted',
                'move_type' => 'out_invoice',
                'amount_total' => 100.0,
                'amount_residual' => 50.0,
                'payment_state' => 'partial',
            ],
        ]);

        $invoices = $this->service->getOutstanding(10);

        self::assertCount(1, $invoices);
        self::assertEquals(50.0, $invoices[0]->amountResidual);
    }

    public function testListPeppolReady(): void
    {
        $this->client->mockResponse('account.move', 'search_read', [
            ['id' => 1, 'name' => 'INV/2026/001', 'state' => 'posted', 'peppol_move_state' => 'to_send'],
        ]);

        $invoices = $this->service->listPeppolReady();

        self::assertCount(1, $invoices);
        $lastCall = $this->client->getLastCall();
        /** @var array<int, mixed> $domain */
        $domain = $lastCall['params']['domain'] ?? [];

        self::assertContains(['peppol_move_state', '=', 'to_send'], $domain);
        self::assertContains(['state', '=', 'posted'], $domain);
    }

    public function testListByPeppolStatus(): void
    {
        $this->client->mockResponse('account.move', 'search_read', [
            ['id' => 1, 'name' => 'INV/2026/001', 'peppol_move_state' => 'error'],
        ]);

        $invoices = $this->service->listByPeppolStatus('error');

        self::assertCount(1, $invoices);
        $lastCall = $this->client->getLastCall();
        /** @var array<int, mixed> $domain */
        $domain = $lastCall['params']['domain'] ?? [];

        self::assertContains(['peppol_move_state', '=', 'error'], $domain);
    }

    public function testListUnpaid(): void
    {
        $this->client->mockResponse('account.move', 'search_read', [
            ['id' => 1, 'payment_state' => 'not_paid', 'state' => 'posted'],
        ]);

        $invoices = $this->service->listUnpaid();

        self::assertCount(1, $invoices);
        $lastCall = $this->client->getLastCall();
        /** @var array<int, mixed> $domain */
        $domain = $lastCall['params']['domain'] ?? [];

        self::assertContains(['payment_state', 'in', ['not_paid', 'partial']], $domain);
        self::assertContains(['state', '=', 'posted'], $domain);
    }

    public function testDownloadPdf(): void
    {
        $this->client->mockResponse('account.move', 'read', [
            ['id' => 1, 'access_token' => 'token123', 'access_url' => '/my/invoices/1'],
        ]);

        $this->expectException(\LeKoala\OdooClient\OdooException::class);
        $this->expectExceptionMessage('Failed to download PDF');

        $this->service->downloadPdf(1);
    }

    public function testListPropagatesPaginationOptions(): void
    {
        $this->client->mockResponse('account.move', 'search_read', []);

        $this->service->list([], new QueryOptions(limit: 25, offset: 50, order: 'invoice_date desc'));

        $params = $this->client->getLastCall()['params'];
        self::assertSame(25, $params['limit']);
        self::assertSame(50, $params['offset']);
        self::assertSame('invoice_date desc', $params['order']);
    }

    public function testDownloadPdfUsesSharedTransportWithoutApiCredentials(): void
    {
        $this->client->mockResponse('account.move', 'read', [
            ['id' => 1, 'access_token' => 'token123', 'access_url' => '/my/invoices/1'],
        ]);
        $transport = new RecordingTransport([
            new HttpResponse(200, ['Content-Type' => ['application/pdf']], '%PDF-content', ''),
        ]);
        $this->client->getConfig()->setHttpTransport($transport);

        self::assertSame('%PDF-content', $this->service->downloadPdf(1));
        self::assertCount(1, $transport->requests);
        self::assertStringContainsString('access_token=token123', $transport->requests[0]->url);
        self::assertArrayNotHasKey('Authorization', $transport->requests[0]->headers);
        self::assertArrayNotHasKey('X-Odoo-Database', $transport->requests[0]->headers);
    }

    public function testGetPortalUrl(): void
    {
        $this->client->mockResponse('account.move', 'read', [
            ['id' => 1, 'access_token' => 'token123', 'access_url' => '/my/invoices/1'],
        ]);

        $url = $this->service->getPortalUrl(1);

        self::assertIsString($url);
        self::assertStringContainsString('token123', $url);
        self::assertStringContainsString('/my/invoices/1', $url);
        self::assertStringStartsWith('http', $url);
    }

    public function testGetPortalUrlGeneratesTokenWhenMissing(): void
    {
        $this->client->mockResponseSequence('account.move', 'read', [
            [['id' => 1, 'access_token' => false, 'access_url' => false]],
            [['id' => 1, 'access_token' => 'freshtoken', 'access_url' => '/my/invoices/1']],
        ]);

        $url = $this->service->getPortalUrl(1);

        self::assertIsString($url);
        self::assertStringContainsString('freshtoken', $url);

        $calls = array_column($this->client->getCallLog(), 'method');
        self::assertContains('get_portal_url', $calls);
    }

    public function testDownloadPdfGeneratesTokenWhenMissing(): void
    {
        $this->client->mockResponse('account.move', 'read', [
            ['id' => 1, 'access_token' => false, 'access_url' => false],
        ]);

        try {
            $this->service->downloadPdf(1);
            self::fail('Expected an OdooException to be thrown');
        } catch (\LeKoala\OdooClient\OdooException $e) {
            self::assertStringContainsString('has no portal access token', $e->getMessage());
        }

        $calls = array_column($this->client->getCallLog(), 'method');
        self::assertContains('get_portal_url', $calls);
    }

    public function testSendViaPeppol(): void
    {
        $this->client->mockResponse('account.move', 'action_process_edi_web_services', true);

        $this->service->sendViaPeppol(1);

        $lastCall = $this->client->getLastCall();
        self::assertEquals('account.move', $lastCall['model']);
        self::assertEquals('action_process_edi_web_services', $lastCall['method']);
        self::assertEquals([1], $lastCall['params']['ids']);
    }
}
