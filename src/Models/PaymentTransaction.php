<?php

namespace LeKoala\OdooClient\Models;

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
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->rawData = $data;
        $this->id = isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : 0;
        $state = $data['state'] ?? '';
        $this->state = is_string($state) ? $state : '';
        $this->amount = isset($data['amount']) && is_numeric($data['amount']) ? (float) $data['amount'] : null;
        $this->reference = isset($data['reference']) && is_string($data['reference']) ? $data['reference'] : null;
        $providerReference = $data['provider_reference'] ?? null;
        $this->providerReference = is_string($providerReference) ? $providerReference : null;
        $operation = $data['operation'] ?? null;
        $this->operation = is_string($operation) ? $operation : null;
        $this->tokenId = self::many2oneId($data['token_id'] ?? null);
        $this->paymentId = self::many2oneId($data['payment_id'] ?? null);
        $methodCode = $data['payment_method_code'] ?? null;
        $this->paymentMethodCode = is_string($methodCode) && $methodCode !== '' ? $methodCode : null;
        $stateMessage = $data['state_message'] ?? null;
        $this->stateMessage = is_string($stateMessage) ? $stateMessage : null;
        $lastChange = $data['last_state_change'] ?? null;
        $this->lastStateChange = is_string($lastChange) ? $lastChange : null;
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

    private static function many2oneId(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
