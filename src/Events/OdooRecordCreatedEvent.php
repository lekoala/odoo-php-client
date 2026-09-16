<?php

namespace LeKoala\OdooClient\Events;

/**
 * Dispatched when a new record is successfully created in Odoo.
 */
class OdooRecordCreatedEvent extends OdooEvent
{
    /** @var array<string, mixed> */
    private array $values;

    /**
     * @param string $model
     * @param int $id
     * @param array<string, mixed> $values
     */
    public function __construct(string $model, int $id, array $values = [])
    {
        parent::__construct($model, $id);
        $this->values = $values;
    }

    /**
     * @return array<string, mixed>
     */
    public function getValues(): array
    {
        return $this->values;
    }
}
