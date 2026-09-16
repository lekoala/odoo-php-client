<?php

namespace LeKoala\OdooClient\Inputs;

use DateTimeInterface;

class SubscriptionInput
{
    /**
     * @param int $partnerId
     * @param int|null $planId
     * @param string|null $state
     * @param DateTimeInterface|string|null $startDate
     * @param DateTimeInterface|string|null $endDate
     * @param array<int, array<int, mixed>> $orderLines Array of order line command tuples [0, 0, {product_id, ...}]
     * @param array<string, mixed> $extraData
     */
    public function __construct(
        public int $partnerId,
        public ?int $planId = null,
        public ?string $state = null,
        public DateTimeInterface|string|null $startDate = null,
        public DateTimeInterface|string|null $endDate = null,
        public array $orderLines = [],
        public array $extraData = []
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = array_merge($this->extraData, [
            'is_subscription' => true,
        ]);

        if ($this->partnerId > 0) {
            $data['partner_id'] = $this->partnerId;
        }

        if ($this->planId !== null && $this->planId > 0) {
            $data['plan_id'] = $this->planId;
        }
        if ($this->state !== null) {
            $data['state'] = $this->state;
        }
        if ($this->startDate !== null) {
            $data['start_date'] = $this->normalizeDate($this->startDate);
        }
        if ($this->endDate !== null) {
            $data['end_date'] = $this->normalizeDate($this->endDate);
        }
        if (!empty($this->orderLines)) {
            $data['order_line'] = $this->orderLines;
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
