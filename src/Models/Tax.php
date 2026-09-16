<?php

namespace LeKoala\OdooClient\Models;

/**
 * Represents a Tax configuration in Odoo.
 *
 * Maps to the `account.tax` model.
 */
class Tax
{
    public int $id;
    public string $name;
    public float $amount;
    public string $typeTaxUse; // 'sale', 'purchase', 'none'
    public string $amountType; // 'percent', 'fixed', etc.

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->id = isset($data['id']) && is_int($data['id']) ? $data['id'] : 0;
        $this->name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
        $this->amount = isset($data['amount']) && is_numeric($data['amount']) ? (float)$data['amount'] : 0.0;
        $this->typeTaxUse = isset($data['type_tax_use']) && is_string($data['type_tax_use']) ? $data['type_tax_use'] : 'sale';
        $this->amountType = isset($data['amount_type']) && is_string($data['amount_type']) ? $data['amount_type'] : 'percent';
    }
}
