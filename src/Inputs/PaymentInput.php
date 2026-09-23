<?php

namespace LeKoala\OdooClient\Inputs;

use DateTimeInterface;

class PaymentInput
{
    /**
     * @param int|int[] $invoiceIds
     * @param array<string, mixed> $extraData
     */
    public function __construct(
        public int|array $invoiceIds,
        public float $amount,
        public ?int $journalId = null,
        public DateTimeInterface|string|null $paymentDate = null,
        public ?int $paymentMethodLineId = null,
        /** Caller-owned correlation reference, propagated as the payment communication. */
        public ?string $communication = null,
        public ?int $paymentTokenId = null,
        public array $extraData = []
    ) {}

    /** @return int[] */
    public function getInvoiceIds(): array
    {
        return is_array($this->invoiceIds) ? $this->invoiceIds : [$this->invoiceIds];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = array_merge($this->extraData, [
            'amount' => $this->amount,
        ]);

        if ($this->journalId !== null) {
            $data['journal_id'] = $this->journalId;
        }
        if ($this->paymentDate !== null) {
            $data['payment_date'] = $this->normalizeDate($this->paymentDate);
        }
        if ($this->paymentMethodLineId !== null) {
            $data['payment_method_line_id'] = $this->paymentMethodLineId;
        }
        if ($this->communication !== null) {
            $data['communication'] = $this->communication;
        }
        if ($this->paymentTokenId !== null) {
            $data['payment_token_id'] = $this->paymentTokenId;
        }

        return $data;
    }

    private function normalizeDate(DateTimeInterface|string $date): string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        $timestamp = strtotime($date);

        return $timestamp === false ? $date : date('Y-m-d', $timestamp);
    }
}
