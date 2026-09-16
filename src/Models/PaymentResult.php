<?php

namespace LeKoala\OdooClient\Models;

use LeKoala\OdooClient\Enums\PaymentResolution;

/**
 * Result of an Odoo payment registration.
 *
 * A result can contain several payments and transactions, notably when the
 * registration wizard processes several invoices at once.
 */
final readonly class PaymentResult
{
    /** @var list<int> */
    public array $paymentIds;

    /** @var list<int> */
    public array $transactionIds;

    public ?int $tokenId;

    public PaymentResolution $resolution;

    /**
     * @param array<mixed> $paymentIds
     * @param array<mixed> $transactionIds
     */
    public function __construct(
        array $paymentIds,
        array $transactionIds = [],
        ?int $tokenId = null,
        PaymentResolution $resolution = PaymentResolution::Ambiguous
    ) {
        $this->paymentIds = self::normalizeIds($paymentIds);
        $this->transactionIds = self::normalizeIds($transactionIds);
        $this->tokenId = $tokenId !== null && $tokenId > 0 ? $tokenId : null;
        $this->resolution = $resolution;
    }

    public function isAmbiguous(): bool
    {
        return $this->resolution === PaymentResolution::Ambiguous;
    }

    /**
     * @return array{
     *     paymentIds: list<int>,
     *     transactionIds: list<int>,
     *     tokenId: int|null,
     *     resolution: string
     * }
     */
    public function toArray(): array
    {
        return [
            'paymentIds' => $this->paymentIds,
            'transactionIds' => $this->transactionIds,
            'tokenId' => $this->tokenId,
            'resolution' => $this->resolution->value,
        ];
    }

    /**
     * @param array<mixed> $values
     * @return list<int>
     */
    private static function normalizeIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[(int) $value] = (int) $value;
            }
        }

        return array_values($ids);
    }
}
