<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Services\PaymentService;
use LeKoala\OdooClient\Events\PaymentRegisteredEvent;
use LeKoala\OdooClient\Enums\PaymentResolution;
use LeKoala\OdooClient\Models\PaymentResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\LeKoala\OdooClient\Services\PaymentService::class)]
class PaymentServiceTest extends TestCase
{
    private MockClient $client;
    private PaymentService $service;

    protected function setUp(): void
    {
        $this->client = new MockClient();
        $this->service = new PaymentService($this->client);
    }

    public function testGetStripeProviderId(): void
    {
        $this->client->mockResponse('payment.provider', 'search_read', [['id' => 7]]);

        $id = $this->service->getStripeProviderId();

        self::assertEquals(7, $id);
        $lastCall = $this->client->getLastCall();
        /** @var array<int, mixed> $domain */
        $domain = $lastCall['params']['domain'] ?? [];
        self::assertContains(['code', '=', 'stripe'], $domain);
    }

    public function testGetStripeProviders(): void
    {
        $this->client->mockResponse('payment.provider', 'search_read', [[
            'id' => 7,
            'name' => 'Stripe',
            'state' => 'test',
            'company_id' => [3, 'MC BE'],
        ]]);

        $providers = $this->service->getStripeProviders();

        self::assertCount(1, $providers);
        self::assertEquals('test', $providers[0]['state']);

        $lastCall = $this->client->getLastCall();
        /** @var array<int, mixed> $domain */
        $domain = $lastCall['params']['domain'] ?? [];
        self::assertContains(['code', '=', 'stripe'], $domain);
    }

    public function testImportStripeToken(): void
    {
        $this->client->mockResponse('payment.method', 'search_read', [['id' => 69]]);
        $this->client->mockResponse('payment.token', 'create', 101);
        $this->client->mockResponse('payment.token', 'read', [[
            'id' => 101,
            'provider_ref' => 'cus_test',
            'stripe_payment_method' => 'pm_test',
        ]]);

        $id = $this->service->importStripeToken(42, 'cus_test', 'pm_test', '•••• 4242', 7);

        self::assertEquals(101, $id);

        $createCall = null;
        foreach ($this->client->getCallLog() as $call) {
            if ($call['method'] === 'create') {
                $createCall = $call;
                break;
            }
        }
        self::assertNotNull($createCall, 'The token must be created');
        /** @var array<string, mixed> $values */
        $values = $createCall['params']['vals_list'][0] ?? [];
        self::assertEquals('cus_test', $values['provider_ref'] ?? null);
        self::assertEquals('pm_test', $values['stripe_payment_method'] ?? null);
        self::assertEquals(42, $values['partner_id'] ?? null);
        self::assertArrayNotHasKey('name', $values);
        self::assertEquals('•••• 4242', $values['payment_details'] ?? null);
        self::assertEquals(69, $values['payment_method_id'] ?? null);
    }

    public function testImportStripeTokenReturnsTheExistingTokenInsteadOfCreatingASecond(): void
    {
        $this->client->mockResponse('payment.token', 'search', [55]);
        $this->client->mockResponse('payment.token', 'create', 101);

        $id = $this->service->importStripeToken(42, 'cus_test', 'pm_test', '•••• 4242', 7);

        self::assertEquals(55, $id);
        self::assertNotEquals('create', $this->client->getLastCall()['method']);

        $domain = $this->client->getCallLog()[0]['params']['domain'];
        self::assertContains(['partner_id', '=', 42], $domain);
        self::assertContains(['provider_ref', '=', 'cus_test'], $domain);
        self::assertContains(['stripe_payment_method', '=', 'pm_test'], $domain);
        self::assertContains(['provider_id', '=', 7], $domain);
        self::assertContains(['active', '=', true], $domain);
    }

    public function testImportStripeTokenIsIdempotentAcrossCalls(): void
    {
        $this->client->mockResponseSequence('payment.token', 'search', [[], [101]]);
        $this->client->mockResponse('payment.method', 'search_read', [['id' => 69]]);
        $this->client->mockResponse('payment.token', 'create', 101);
        $this->client->mockResponse('payment.token', 'read', [[
            'id' => 101,
            'provider_ref' => 'cus_test',
            'stripe_payment_method' => 'pm_test',
        ]]);

        $first = $this->service->importStripeToken(42, 'cus_test', 'pm_test', '•••• 4242', 7);
        $second = $this->service->importStripeToken(42, 'cus_test', 'pm_test', '•••• 4242', 7);

        self::assertEquals(101, $first);
        self::assertEquals($first, $second);

        $creates = array_filter(
            $this->client->getCallLog(),
            static fn (array $call): bool => ($call['method'] ?? '') === 'create'
        );
        self::assertCount(1, $creates);
    }

    public function testGetCardPaymentMethodIdIsScopedToTheProvider(): void
    {
        $this->client->mockResponse('payment.method', 'search_read', [['id' => 69]]);

        self::assertSame(69, $this->service->getCardPaymentMethodId(14));

        $lastCall = $this->client->getLastCall();
        /** @var array<int, mixed> $domain */
        $domain = $lastCall['params']['domain'] ?? [];
        self::assertContains(['code', '=', 'card'], $domain);
        self::assertContains(['active', '=', true], $domain);
        self::assertContains(['provider_ids', 'in', [14]], $domain);
    }

    public function testGetCardPaymentMethodIdReturnsNullWhenProviderHasNoCardMethod(): void
    {
        $this->client->mockResponse('payment.method', 'search_read', []);

        self::assertNull($this->service->getCardPaymentMethodId(14));
    }

    public function testImportStripeTokenFailsWhenProviderHasNoCardMethod(): void
    {
        $this->client->mockResponse('payment.method', 'search_read', []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("has no active 'card' payment method");

        $this->service->importStripeToken(42, 'cus_test', 'pm_test', '•••• 4242', 7);
    }

    public function testImportStripeTokenCreatesNothingWithoutACardMethod(): void
    {
        $this->client->mockResponse('payment.method', 'search_read', []);

        try {
            $this->service->importStripeToken(42, 'cus_test', 'pm_test', '•••• 4242', 7);
        } catch (\RuntimeException $e) {
        }

        foreach ($this->client->getCallLog() as $call) {
            self::assertFalse(
                $call['model'] === 'payment.token' && $call['method'] === 'create',
                'A token must not be created when no card payment method could be resolved.'
            );
        }
    }

    public function testProcessPaymentTokenReturnsRealPaymentId(): void
    {
        $this->client->mockResponse('account.payment.register', 'create', 555);
        $this->client->mockResponse('account.payment.register', 'action_create_payments', true);
        $this->client->mockResponse('account.payment', 'search', [61]);

        $result = $this->service->processPaymentToken(10, 250.00, 99);

        self::assertSame([61], $result->paymentIds);
        self::assertSame(PaymentResolution::Reconciled, $result->resolution);

        $calls = $this->client->getCallLog();
        self::assertCount(4, $calls);

        $createCall = $calls[0];
        self::assertEquals('account.payment.register', $createCall['model']);
        self::assertEquals('create', $createCall['method']);
        /** @var array<string, mixed> $createValues */
        $createValues = $createCall['params']['vals_list'][0] ?? [];
        self::assertEquals(250.00, $createValues['amount'] ?? 0.0);
        self::assertEquals(99, $createValues['payment_token_id'] ?? 0);

        /** @var array<string, mixed> $createContext */
        $createContext = $createCall['params']['context'] ?? [];
        self::assertEquals([10], $createContext['active_ids'] ?? []);

        $actionCall = $calls[1];
        self::assertEquals('account.payment.register', $actionCall['model']);
        self::assertEquals('action_create_payments', $actionCall['method']);
        self::assertEquals([555], $actionCall['params']['ids']);

        $searchCall = $calls[2];
        self::assertEquals('account.payment', $searchCall['model']);
        self::assertEquals('search', $searchCall['method']);

        $events = $this->client->getDispatchedEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(PaymentRegisteredEvent::class, $events[0]);

        /** @var PaymentRegisteredEvent $event */
        $event = $events[0];
        self::assertEquals(61, $event->getId());
        self::assertEquals(10, $event->getInvoiceId());
        self::assertEquals('account.payment', $event->getModel());
    }

    public function testRegisterPaymentReturnsZeroWhenNoPaymentFound(): void
    {
        $this->client->mockResponse('account.payment.register', 'create', 555);
        $this->client->mockResponse('account.payment.register', 'action_create_payments', true);
        $this->client->mockResponse('account.payment', 'search', []);

        $result = $this->service->processPaymentToken(10, 250.00, 99);

        self::assertSame([], $result->paymentIds);
        self::assertSame(PaymentResolution::Ambiguous, $result->resolution);
    }

    public function testFindPaymentsForInvoiceReturnsPaymentIds(): void
    {
        $this->client->mockResponse('account.payment', 'search', [42, 43]);

        $ids = $this->service->findPaymentsForInvoice(10);

        self::assertSame([42, 43], $ids);

        $lastCall = $this->client->getLastCall();
        self::assertEquals('account.payment', $lastCall['model']);
        self::assertEquals('search', $lastCall['method']);
        /** @var array<int, mixed> $domain */
        $domain = $lastCall['params']['domain'] ?? [];
        self::assertContains(['reconciled_invoice_ids', 'in', [10]], $domain);
    }

    public function testFindPaymentsForInvoiceReturnsEmptyWhenNone(): void
    {
        $this->client->mockResponse('account.payment', 'search', []);

        $ids = $this->service->findPaymentsForInvoice(99);

        self::assertSame([], $ids);
    }

    public function testProcessPaymentTokenReadsThePaymentIdFromTheActionResId(): void
    {
        $this->client->mockResponse('account.payment.register', 'create', 555);
        $this->client->mockResponse('account.payment.register', 'action_create_payments', [
            'type' => 'ir.actions.act_window',
            'res_model' => 'account.payment',
            'view_mode' => 'form',
            'res_id' => 61,
        ]);

        $result = $this->service->processPaymentToken(10, 250.00, 99);

        self::assertSame([61], $result->paymentIds);
        self::assertSame(PaymentResolution::Explicit, $result->resolution);

        foreach ($this->client->getCallLog() as $call) {
            self::assertFalse(
                $call['model'] === 'account.payment' && $call['method'] === 'search',
                'The action already named the payment; searching for it again is the call we removed.'
            );
        }
    }

    public function testProcessPaymentTokenReadsThePaymentIdsFromTheActionDomain(): void
    {
        $this->client->mockResponse('account.payment.register', 'create', 555);
        $this->client->mockResponse('account.payment.register', 'action_create_payments', [
            'type' => 'ir.actions.act_window',
            'res_model' => 'account.payment',
            'view_mode' => 'list,form',
            'domain' => [['id', 'in', [61, 62]]],
        ]);

        $result = $this->service->processPaymentToken(10, 250.00, 99);

        self::assertSame([61, 62], $result->paymentIds);
        self::assertSame(PaymentResolution::Explicit, $result->resolution);
    }

    public function testAConcurrentManualPaymentIsNotMistakenForOurs(): void
    {
        $this->client->mockResponse('account.payment.register', 'create', 555);
        $this->client->mockResponse('account.payment.register', 'action_create_payments', [
            'res_id' => 61,
        ]);
        $this->client->mockResponse('account.payment', 'search', [77, 61]);

        $result = $this->service->processPaymentToken(10, 250.00, 99);

        self::assertSame([61], $result->paymentIds);
    }

    public function testProcessPaymentTokenFallsBackToTheSearchWhenTheActionCarriesNoIds(): void
    {
        $this->client->mockResponse('account.payment.register', 'create', 555);
        $this->client->mockResponse('account.payment.register', 'action_create_payments', true);
        $this->client->mockResponse('account.payment', 'search', [61]);

        $result = $this->service->processPaymentToken(10, 250.00, 99);

        self::assertSame([61], $result->paymentIds);
        self::assertSame(PaymentResolution::Reconciled, $result->resolution);
    }

    public function testAnUnrecognisedActionDomainFallsBackToTheSearch(): void
    {
        $this->client->mockResponse('account.payment.register', 'create', 555);
        $this->client->mockResponse('account.payment.register', 'action_create_payments', [
            'domain' => [['journal_id', 'in', [4]]],
        ]);
        $this->client->mockResponse('account.payment', 'search', [61]);

        $result = $this->service->processPaymentToken(10, 250.00, 99);

        self::assertSame([61], $result->paymentIds);
        self::assertSame(PaymentResolution::Reconciled, $result->resolution);
    }

    public function testRegisterPaymentPreservesAllExplicitPaymentsAndTransactions(): void
    {
        $this->client->mockResponse('account.payment.register', 'create', 555);
        $this->client->mockResponse('account.payment.register', 'action_create_payments', [
            'res_model' => 'account.payment',
            'res_ids' => [61, 62],
        ]);
        $this->client->mockResponse('account.payment', 'read', [
            [
                'id' => 61,
                'payment_transaction_id' => [701, 'TX 701'],
                'payment_token_id' => [99, 'Card'],
                'reconciled_invoice_ids' => [10],
            ],
            [
                'id' => 62,
                'payment_transaction_id' => [702, 'TX 702'],
                'payment_token_id' => [99, 'Card'],
                'reconciled_invoice_ids' => [11],
            ],
        ]);

        $result = $this->service->registerPayment(new \LeKoala\OdooClient\Inputs\PaymentInput(
            invoiceIds: [10, 11],
            amount: 250.00,
            paymentTokenId: 99
        ));

        self::assertSame([61, 62], $result->paymentIds);
        self::assertSame([701, 702], $result->transactionIds);
        self::assertSame(99, $result->tokenId);
        self::assertSame(PaymentResolution::Explicit, $result->resolution);
        self::assertCount(2, $this->client->getDispatchedEvents());
    }

    public function testMultiInvoiceFallbackIsAmbiguousButKeepsEveryCandidate(): void
    {
        $this->client->mockResponse('account.payment.register', 'create', 555);
        $this->client->mockResponse('account.payment.register', 'action_create_payments', true);
        $this->client->mockResponseSequence('account.payment', 'search', [[61, 62], [62]]);

        $result = $this->service->registerPayment(new \LeKoala\OdooClient\Inputs\PaymentInput(
            invoiceIds: [10, 11],
            amount: 250.00
        ));

        self::assertSame([61, 62], $result->paymentIds);
        self::assertSame(PaymentResolution::Ambiguous, $result->resolution);
        self::assertTrue($result->isAmbiguous());
        self::assertSame([], $this->client->getDispatchedEvents());
    }

    public function testMultiInvoiceFallbackCanReconcileDistinctPayments(): void
    {
        $this->client->mockResponse('account.payment.register', 'create', 555);
        $this->client->mockResponse('account.payment.register', 'action_create_payments', true);
        $this->client->mockResponseSequence('account.payment', 'search', [[61], [62]]);

        $result = $this->service->registerPayment(new \LeKoala\OdooClient\Inputs\PaymentInput(
            invoiceIds: [10, 11],
            amount: 250.00
        ));

        self::assertSame([61, 62], $result->paymentIds);
        self::assertSame(PaymentResolution::Reconciled, $result->resolution);
    }

    public function testPaymentResultCanBeSerialized(): void
    {
        $result = new PaymentResult([61, 61, 62], [701], 99, PaymentResolution::Explicit);

        self::assertSame([
            'paymentIds' => [61, 62],
            'transactionIds' => [701],
            'tokenId' => 99,
            'resolution' => 'explicit',
        ], $result->toArray());
    }

    public function testChargeInvoiceWithTokenPreservesMultiplePayments(): void
    {
        $this->client->mockResponse('account.payment.register', 'create', 555);
        $this->client->mockResponse('account.payment.register', 'read', [[
            'payment_token_id' => [99, 'Card'],
            'suitable_payment_token_ids' => [99],
            'use_electronic_payment_method' => true,
            'amount' => 250.00,
        ]]);
        $this->client->mockResponse('account.payment.register', 'action_create_payments', [
            'res_model' => 'account.payment',
            'domain' => [['id', 'in', [61, 62]]],
        ]);
        $this->client->mockResponse('account.payment', 'read', [
            ['id' => 61, 'payment_transaction_id' => [701, 'TX 701'], 'payment_token_id' => [99, 'Card']],
            ['id' => 62, 'payment_transaction_id' => [702, 'TX 702'], 'payment_token_id' => [99, 'Card']],
        ]);

        $result = $this->service->chargeInvoiceWithToken(10, 250.00, 99, 4, 5);

        self::assertSame([61, 62], $result->paymentIds);
        self::assertSame([701, 702], $result->transactionIds);
        self::assertSame(99, $result->tokenId);
        self::assertSame(PaymentResolution::Explicit, $result->resolution);
    }

    public function testMetadataFailureAfterPaymentDoesNotInviteADuplicateRetry(): void
    {
        $this->client->mockResponse('account.payment.register', 'create', 555);
        $this->client->mockResponse('account.payment.register', 'action_create_payments', [
            'res_model' => 'account.payment',
            'res_id' => 61,
        ]);
        $this->client->mockException(
            'account.payment',
            'read',
            new \LeKoala\OdooClient\OdooException('Temporary read failure')
        );

        $result = $this->service->processPaymentToken(10, 250.00, 99);

        self::assertSame([61], $result->paymentIds);
        self::assertSame([], $result->transactionIds);
        self::assertNull($result->tokenId);
        self::assertSame(PaymentResolution::Explicit, $result->resolution);
    }
}
