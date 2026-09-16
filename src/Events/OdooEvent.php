<?php

namespace LeKoala\OdooClient\Events;

/**
 * Base event class for Odoo interactions.
 */
abstract class OdooEvent
{
    private string $model;
    private int $id;

    public function __construct(string $model, int $id)
    {
        $this->model = $model;
        $this->id = $id;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getId(): int
    {
        return $this->id;
    }
}
