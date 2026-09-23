<?php

namespace LeKoala\OdooClient\Models;

use LeKoala\OdooClient\OdooValue;

class Product
{
    public ?int $id;
    public string $name;
    public ?string $defaultCode; // Internal Reference
    public float $listPrice;
    public ?int $categoryId;
    public string $type; // service, product, conso
    public ?string $description;
    /** @var array<int> */
    public array $taxIds;
    public bool $active;

    /**
     * Decode an Odoo `product.product` row.
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
        $this->id = OdooValue::id($data['id'] ?? null);
        $this->name = OdooValue::stringOr($data['name'] ?? null);
        $this->defaultCode = OdooValue::nullableString($data['default_code'] ?? null);
        $this->listPrice = OdooValue::floatOr($data['list_price'] ?? null);
        $this->categoryId = OdooValue::many2oneId($data['categ_id'] ?? null);
        $this->type = OdooValue::nullableString($data['detailed_type'] ?? null)
            ?? OdooValue::nullableString($data['type'] ?? null)
            ?? 'service';
        $this->description = OdooValue::nullableString($data['description'] ?? null);

        $this->taxIds = OdooValue::ids($data['taxes_id'] ?? null);

        $this->active = !isset($data['active']) || !empty($data['active']);
    }
}
