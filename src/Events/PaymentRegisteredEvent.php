<?php

namespace LeKoala\OdooClient\Events;

/**
 * Dispatched when a payment is registered for an invoice.
 *
 * Registration is not payment: the transaction may still be pending.
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
     * Get the invoice associated with the registered payment.
     */
    public function getInvoiceId(): int
    {
        return $this->invoiceId;
    }
}
