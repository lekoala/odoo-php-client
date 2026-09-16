<?php

namespace LeKoala\OdooClient\Inputs;

class InvoiceLineInput
{
    public function __construct(
        public int $productId,
        public float $quantity = 1.0,
        public ?float $priceUnit = null,
        public ?string $name = null
    ) {}

    /** @return array<int, mixed> */
    public function toArray(): array
    {
        $data = [
            'product_id' => $this->productId,
            'quantity' => $this->quantity,
        ];

        if ($this->priceUnit !== null) {
            $data['price_unit'] = $this->priceUnit;
        }
        if ($this->name !== null) {
            $data['name'] = $this->name;
        }

        return [0, 0, $data];
    }
}
