<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Models\Invoice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\LeKoala\OdooClient\Models\Invoice::class)]
class InvoiceModelTest extends TestCase
{
    public function testModelMappingWithPeppol(): void
    {
        $data = [
            'id' => 101,
            'name' => 'INV/2026/0001',
            'partner_id' => [42, 'Douglas Adams'],
            'state' => 'posted',
            'peppol_move_state' => 'to_send',
        ];

        $invoice = new Invoice($data);

        self::assertEquals(101, $invoice->id);
        self::assertEquals('INV/2026/0001', $invoice->name);
        self::assertEquals('to_send', $invoice->peppolMoveState);
    }

    public function testPortalAccessMapping(): void
    {
        $data = ['access_token' => 'token123', 'access_url' => '/my/invoices/1'];
        $invoice = new Invoice($data);
        self::assertEquals('token123', $invoice->accessToken);
        self::assertEquals('/my/invoices/1', $invoice->accessUrl);
    }

    public function testPeppolStatusHelpers(): void
    {
        $invoice = new Invoice(['state' => 'posted', 'peppol_move_state' => 'to_send']);
        self::assertTrue($invoice->isPeppolReady());
        self::assertFalse($invoice->isPeppolSent());
        self::assertEquals('Ready to Send', $invoice->getPeppolStatusLabel());

        $invoice = new Invoice(['state' => 'posted', 'peppol_move_state' => 'done']);
        self::assertFalse($invoice->isPeppolReady());
        self::assertTrue($invoice->isPeppolSent());
        self::assertEquals('Sent', $invoice->getPeppolStatusLabel());

        $invoice = new Invoice(['state' => 'posted', 'peppol_move_state' => 'error']);
        self::assertTrue($invoice->hasPeppolError());
        self::assertEquals('Error', $invoice->getPeppolStatusLabel());

        $invoice = new Invoice(['state' => 'posted', 'peppol_move_state' => 'processing']);
        self::assertEquals('Processing', $invoice->getPeppolStatusLabel());
    }

    public function testPaymentStatusHelpers(): void
    {
        $invoice = new Invoice(['state' => 'posted', 'payment_state' => 'paid', 'amount_residual' => 0.0]);
        self::assertTrue($invoice->isPaid());
        self::assertFalse($invoice->isPartial());
        self::assertEquals(0.0, $invoice->getAmountDue());

        $invoice = new Invoice(['state' => 'posted', 'payment_state' => 'partial', 'amount_residual' => 50.0]);
        self::assertFalse($invoice->isPaid());
        self::assertTrue($invoice->isPartial());
        self::assertEquals(50.0, $invoice->getAmountDue());

        $invoice = new Invoice(['state' => 'posted', 'payment_state' => 'not_paid', 'amount_residual' => 100.0]);
        self::assertTrue($invoice->isNotPaid());
        self::assertEquals(100.0, $invoice->getAmountDue());
    }

    public function testIsPeppolReadyRequiresPostedState(): void
    {
        $invoice = new Invoice(['state' => 'draft', 'peppol_move_state' => 'to_send']);
        self::assertFalse($invoice->isPeppolReady(), 'Draft invoices should not be Peppol-ready');
    }
}
