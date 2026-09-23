<?php

namespace LeKoala\OdooClient\Models;

use LeKoala\OdooClient\OdooValue;

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
     * Decode an Odoo `account.tax` row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromOdoo(array $row): self
    {
        return new self($row);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->id = OdooValue::id($data['id'] ?? null) ?? 0;
        $this->name = OdooValue::stringOr($data['name'] ?? null);
        $this->amount = OdooValue::floatOr($data['amount'] ?? null);
        $this->typeTaxUse = OdooValue::stringOr($data['type_tax_use'] ?? null, 'sale');
        $this->amountType = OdooValue::stringOr($data['amount_type'] ?? null, 'percent');
    }
}
