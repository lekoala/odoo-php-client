<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Models\PaymentToken;
use LeKoala\OdooClient\Models\PaymentTransaction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaymentTransaction::class)]
#[CoversClass(PaymentToken::class)]
class PaymentTransactionTest extends TestCase
{
    public function testTransactionHydrationKeepsRawState(): void
    {
        $tx = new PaymentTransaction([
            'id' => 701,
            'state' => 'pending',
            'amount' => 29.90,
            'reference' => 'PAY-456',
            'provider_reference' => 'pi_test',
            'operation' => 'online_direct',
            'token_id' => [99, 'Card'],
            'payment_id' => [61, 'PAY/001'],
            'payment_method_code' => 'sepa_direct_debit',
            'state_message' => 'Waiting',
            'last_state_change' => '2026-01-01 10:00:00',
        ]);

        self::assertSame(701, $tx->id);
        self::assertSame('pending', $tx->state);
        self::assertTrue($tx->isPending());
        self::assertFalse($tx->isDone());
        self::assertSame(99, $tx->tokenId);
        self::assertSame(61, $tx->paymentId);
        self::assertSame('sepa_direct_debit', $tx->paymentMethodCode);
    }

    public function testTransactionStatesAreMechanicalOnly(): void
    {
        self::assertTrue((new PaymentTransaction(['id' => 1, 'state' => 'done']))->isDone());
        self::assertTrue((new PaymentTransaction(['id' => 1, 'state' => 'error']))->isFailed());
        self::assertTrue((new PaymentTransaction(['id' => 1, 'state' => 'cancel']))->isCancelled());
        self::assertTrue((new PaymentTransaction(['id' => 1, 'state' => 'canceled']))->isCancelled());

        $future = new PaymentTransaction(['id' => 1, 'state' => 'some_future_state']);
        self::assertSame('some_future_state', $future->state);
        self::assertFalse($future->isPending());
        self::assertFalse($future->isDone());
        self::assertFalse($future->isFailed());
        self::assertFalse($future->isCancelled());
    }

    public function testTokenExposesMethodCodeAndStripeFields(): void
    {
        $token = new PaymentToken([
            'id' => 99,
            'name' => 'Card',
            'provider_ref' => 'cus_test',
            'partner_id' => [42, 'Alice'],
            'provider_id' => [7, 'Stripe'],
            'active' => true,
            'payment_method_id' => [69, 'Card'],
            'payment_method_code' => 'sepa_direct_debit',
            'stripe_payment_method' => 'pm_test',
            'stripe_mandate' => 'mandate_test',
        ]);

        self::assertSame(69, $token->paymentMethodId);
        self::assertSame('sepa_direct_debit', $token->paymentMethodCode);
        self::assertSame('pm_test', $token->stripePaymentMethod);
        self::assertSame('mandate_test', $token->stripeMandate);
        self::assertSame('cus_test', $token->providerRef);
    }

    public function testTokenKeepsNullWhenStripeFieldsAreAbsent(): void
    {
        $token = new PaymentToken(['id' => 99]);

        self::assertNull($token->paymentMethodId);
        self::assertNull($token->paymentMethodCode);
        self::assertNull($token->stripePaymentMethod);
        self::assertNull($token->stripeMandate);
    }
}
