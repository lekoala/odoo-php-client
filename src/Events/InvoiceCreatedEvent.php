<?php

namespace LeKoala\OdooClient\Events;

/**
 * Dispatched when a new invoice is created.
 */
class InvoiceCreatedEvent extends OdooEvent
{
    /**
     * @param int $id The ID of the newly created invoice.
     */
    public function __construct(int $id)
    {
        parent::__construct('account.move', $id);
    }
}
