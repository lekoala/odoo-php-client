<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Exceptions\OdooNetworkException;
use LeKoala\OdooClient\Exceptions\PaymentOutcomeUnknownException;
use LeKoala\OdooClient\Inputs\PaymentInput;
use LeKoala\OdooClient\OdooException;
use LeKoala\OdooClient\Services\PaymentService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaymentService::class)]
#[CoversClass(PaymentOutcomeUnknownException::class)]
class PaymentOutcomeUnknownTest extends TestCase
{
    private MockClient $client;
    private PaymentService $service;

    protected function setUp(): void
    {
        $this->client = new MockClient();
        $this->service = new PaymentService($this->client);
    }

    public function testRegisterPaymentNetworkFailureBecomesUnknown(): void
    {
        $this->client->mockResponse('account.payment.register', 'create', 555);
        $this->client->mockException(
            'account.payment.register',
            'action_create_payments',
            new OdooNetworkException('socket cut')
        );

        $input = new PaymentInput(
            invoiceIds: [10],
            amount: 29.90,
            communication: 'PAY-456',
            paymentTokenId: 99
        );

        try {
            $this->service->registerPayment($input);
            self::fail('An unknown outcome must be reported, never a silent result.');
        } catch (PaymentOutcomeUnknownException $e) {
            self::assertSame([10], $e->getInvoiceIds());
            self::assertSame(555, $e->getWizardId());
            self::assertSame('PAY-456', $e->getCommunication());
            self::assertSame(99, $e->getTokenId());
            self::assertInstanceOf(OdooNetworkException::class, $e->getPrevious());
            self::assertInstanceOf(OdooException::class, $e);
            self::assertNotInstanceOf(OdooNetworkException::class, $e);
        }

        $actions = array_filter(
            $this->client->getCallLog(),
            static fn (array $call): bool => ($call['method'] ?? '') === 'action_create_payments'
        );
        self::assertCount(1, $actions, 'The uncertain action must not be replayed automatically.');
    }

    public function testChargeInvoiceWithTokenNetworkFailureBecomesUnknown(): void
    {
        $this->client->mockResponse('account.payment.register', 'create', 555);
        $this->client->mockResponse('account.payment.register', 'read', [[
            'payment_token_id' => [99, 'Card'],
            'suitable_payment_token_ids' => [99],
            'use_electronic_payment_method' => true,
            'amount' => 29.90,
        ]]);
        $this->client->mockException(
            'account.payment.register',
            'action_create_payments',
            new OdooNetworkException('socket cut')
        );

        try {
            $this->service->chargeInvoiceWithToken(10, 29.90, 99, 4, 5);
            self::fail('An unknown outcome must be reported, never a silent result.');
        } catch (PaymentOutcomeUnknownException $e) {
            self::assertSame([10], $e->getInvoiceIds());
            self::assertSame(555, $e->getWizardId());
            self::assertSame(99, $e->getTokenId());
        }

        $actions = array_filter(
            $this->client->getCallLog(),
            static fn (array $call): bool => ($call['method'] ?? '') === 'action_create_payments'
        );
        self::assertCount(1, $actions);
    }

    public function testLaterLookupCanRecoverAfterUnknown(): void
    {
        $this->client->mockResponse('account.payment', 'search', [61]);
        $this->client->mockResponse('payment.transaction', 'search_read', [[
            'id' => 701,
            'state' => 'pending',
            'amount' => 29.90,
            'reference' => 'PAY-456',
            'provider_reference' => 'pi_test',
            'operation' => 'online_direct',
            'token_id' => [99, 'Card'],
            'payment_id' => [61, 'PAY/001'],
            'payment_method_code' => 'card',
            'state_message' => null,
            'last_state_change' => '2026-01-01 10:00:00',
        ]]);

        self::assertSame([61], $this->service->findPaymentsForInvoice(10));

        $transactions = $this->service->findTransactionsForInvoice(10);
        self::assertCount(1, $transactions);
        self::assertSame(701, $transactions[0]->id);
        self::assertTrue($transactions[0]->isPending());
    }

    public function testRecoveryLookupFiltersOnCommunication(): void
    {
        $this->client->mockResponse('account.payment', 'search', [61]);
        // Note: the transaction reference is Odoo-generated (memo-prefixed),
        // never the raw communication — recovery matches via payment_id.memo.
        $this->client->mockResponse('payment.transaction', 'search_read', [[
            'id' => 701,
            'state' => 'pending',
            'amount' => 29.90,
            'reference' => 'TX-PAY-456-01',
            'provider_reference' => 'pi_test',
            'operation' => 'online_direct',
            'token_id' => [99, 'Card'],
            'payment_id' => [61, 'PAY/001'],
            'payment_method_code' => 'card',
            'state_message' => null,
            'last_state_change' => '2026-01-01 10:00:00',
        ]]);

        self::assertSame([61], $this->service->findPaymentsForInvoice(10, 'PAY-456'));

        $searchCall = null;
        foreach ($this->client->getCallLog() as $call) {
            if ($call['model'] === 'account.payment' && $call['method'] === 'search') {
                $searchCall = $call;
            }
        }
        self::assertNotNull($searchCall);
        /** @var array<int, mixed> $paymentDomain */
        $paymentDomain = $searchCall['params']['domain'] ?? [];
        self::assertContains(['reconciled_invoice_ids', 'in', [10]], $paymentDomain);
        self::assertContains(['memo', '=', 'PAY-456'], $paymentDomain);

        $transactions = $this->service->findTransactionsForInvoice(10, 'PAY-456');
        self::assertCount(1, $transactions);

        $searchReadCall = null;
        foreach ($this->client->getCallLog() as $call) {
            if ($call['model'] === 'payment.transaction' && $call['method'] === 'search_read') {
                $searchReadCall = $call;
            }
        }
        self::assertNotNull($searchReadCall);
        /** @var array<int, mixed> $transactionDomain */
        $transactionDomain = $searchReadCall['params']['domain'] ?? [];
        self::assertContains(['invoice_ids', 'in', [10]], $transactionDomain);
        self::assertContains(['payment_id.memo', '=', 'PAY-456'], $transactionDomain);
    }

    public function testLookupWithoutCommunicationKeepsUnfilteredDomain(): void
    {
        $this->client->mockResponse('account.payment', 'search', [61, 62]);
        $this->client->mockResponse('payment.transaction', 'search_read', []);

        self::assertSame([61, 62], $this->service->findPaymentsForInvoice(10));
        self::assertSame([], $this->service->findTransactionsForInvoice(10));

        $searchCall = null;
        foreach ($this->client->getCallLog() as $call) {
            if ($call['model'] === 'account.payment' && $call['method'] === 'search') {
                $searchCall = $call;
            }
        }
        self::assertNotNull($searchCall);
        /** @var array<int, mixed> $paymentDomain */
        $paymentDomain = $searchCall['params']['domain'] ?? [];
        self::assertCount(1, $paymentDomain);
    }
}
