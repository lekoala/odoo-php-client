<?php

namespace LeKoala\OdooClient\Inputs;

class InvoiceInput
{
    /**
     * @param InvoiceLineInput[] $lines
     * @param array<string, mixed> $extraData
     */
    public function __construct(
        public int $partnerId,
        public array $lines,
        public string $moveType = 'out_invoice',
        public array $extraData = []
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $lineData = array_map(fn(InvoiceLineInput $line) => $line->toArray(), $this->lines);

        return array_merge($this->extraData, [
            'partner_id' => $this->partnerId,
            'move_type' => $this->moveType,
            'invoice_line_ids' => $lineData,
        ]);
    }
}
