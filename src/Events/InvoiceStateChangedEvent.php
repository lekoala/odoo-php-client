<?php

namespace LeKoala\OdooClient\Events;

/**
 * Dispatched when an invoice's state is changed (e.g. to 'posted' or 'cancel').
 */
class InvoiceStateChangedEvent extends OdooEvent
{
    private string $newState;

    /**
     * @param int $id
     * @param string $newState
     */
    public function __construct(int $id, string $newState)
    {
        parent::__construct('account.move', $id);
        $this->newState = $newState;
    }

    public function getNewState(): string
    {
        return $this->newState;
    }
}
