<?php

namespace LeKoala\OdooClient\Events;

/**
 * Dispatched when a payment is registered for an invoice.
 */
class PaymentRegisteredEvent extends OdooEvent
{
    private int $invoiceId;

    public function __construct(int $paymentId, int $invoiceId)
    {
        parent::__construct('account.payment', $paymentId);
        $this->invoiceId = $invoiceId;
    }

    /**
     * Get the ID of the invoice that was paid.
     */
    public function getInvoiceId(): int
    {
        return $this->invoiceId;
    }
}
