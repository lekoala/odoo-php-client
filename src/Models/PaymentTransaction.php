<?php

namespace LeKoala\OdooClient\Models;

use LeKoala\OdooClient\OdooValue;

/**
 * A payment transaction (`payment.transaction`) as seen by Odoo.
 *
 * The state is kept as a raw string so a new Odoo state never breaks
 * hydration. Helpers cover the mechanical states only.
 */
class PaymentTransaction
{
    /** @var array<string, mixed> */
    public array $rawData;
    public int $id;
    public string $state;
    public ?float $amount;
    public ?string $reference;
    public ?string $providerReference;
    public ?string $operation;
    public ?int $tokenId;
    public ?int $paymentId;
    public ?string $paymentMethodCode;
    public ?string $stateMessage;
    public ?string $lastStateChange;

    /**
     * Decode an Odoo `payment.transaction` row.
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
        $this->rawData = $data;
        $this->id = OdooValue::id($data['id'] ?? null) ?? 0;
        $this->state = OdooValue::stringOr($data['state'] ?? null);
        $this->amount = isset($data['amount']) && is_numeric($data['amount']) ? (float) $data['amount'] : null;
        $this->reference = OdooValue::nullableString($data['reference'] ?? null);
        $this->providerReference = OdooValue::nullableString($data['provider_reference'] ?? null);
        $this->operation = OdooValue::nullableString($data['operation'] ?? null);
        $this->tokenId = OdooValue::many2oneId($data['token_id'] ?? null);
        $this->paymentId = OdooValue::many2oneId($data['payment_id'] ?? null);
        $methodCode = OdooValue::nullableString($data['payment_method_code'] ?? null);
        $this->paymentMethodCode = ($methodCode === null || $methodCode === '') ? null : $methodCode;
        $this->stateMessage = OdooValue::nullableString($data['state_message'] ?? null);
        $this->lastStateChange = OdooValue::nullableString($data['last_state_change'] ?? null);
    }

    public function isPending(): bool
    {
        return $this->state === 'pending';
    }

    public function isDone(): bool
    {
        return $this->state === 'done';
    }

    public function isFailed(): bool
    {
        return $this->state === 'error';
    }

    public function isCancelled(): bool
    {
        return $this->state === 'cancel' || $this->state === 'canceled' || $this->state === 'cancelled';
    }
}
