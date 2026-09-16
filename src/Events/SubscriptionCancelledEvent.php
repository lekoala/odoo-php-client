<?php

namespace LeKoala\OdooClient\Events;

/**
 * Dispatched when a subscription is cancelled.
 */
class SubscriptionCancelledEvent extends OdooEvent
{
    public function __construct(int $subscriptionId)
    {
        parent::__construct('sale.order', $subscriptionId);
    }
}
