<?php

namespace LeKoala\OdooClient\Models;

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
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->id = isset($data['id']) && is_int($data['id']) ? $data['id'] : null;
        $this->name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
        $this->defaultCode = isset($data['default_code']) && is_string($data['default_code']) ? $data['default_code'] : null;
        $this->listPrice = isset($data['list_price']) && is_numeric($data['list_price']) ? (float)$data['list_price'] : 0.0;
        $this->categoryId = (isset($data['categ_id']) && is_array($data['categ_id']) && isset($data['categ_id'][0]) && is_int($data['categ_id'][0])) ? $data['categ_id'][0] : (isset($data['categ_id']) && is_int($data['categ_id']) ? $data['categ_id'] : null);
        $this->type = isset($data['detailed_type']) && is_string($data['detailed_type']) ? $data['detailed_type'] : (isset($data['type']) && is_string($data['type']) ? $data['type'] : 'service');
        $this->description = isset($data['description']) && is_string($data['description']) ? $data['description'] : null;

        $taxes = isset($data['taxes_id']) && is_array($data['taxes_id']) ? $data['taxes_id'] : [];
        $typedTaxes = [];
        foreach ($taxes as $tid) {
            if (is_int($tid)) $typedTaxes[] = $tid;
        }
        $this->taxIds = $typedTaxes;

        $this->active = !isset($data['active']) || !empty($data['active']);
    }
}
