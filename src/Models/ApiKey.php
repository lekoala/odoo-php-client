<?php

namespace LeKoala\OdooClient\Models;

use LeKoala\OdooClient\OdooValue;

final class ApiKey
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $scope,
        public readonly ?string $expirationDate
    ) {
    }

    /**
     * Decode an Odoo `res.users.apikeys` row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromOdoo(array $row): self
    {
        return self::fromRow($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $scope = OdooValue::nullableString($row['scope'] ?? null);
        $expiry = OdooValue::nullableString($row['expiration_date'] ?? null);

        return new self(
            OdooValue::id($row['id'] ?? null) ?? 0,
            OdooValue::stringOr($row['name'] ?? null),
            ($scope === null || $scope === '') ? null : $scope,
            ($expiry === null || $expiry === '') ? null : $expiry
        );
    }

    public function daysUntilExpiry(?int $now = null): ?int
    {
        if ($this->expirationDate === null) {
            return null;
        }

        $expires = strtotime($this->expirationDate);
        if ($expires === false) {
            return null;
        }

        return (int) floor(($expires - ($now ?? time())) / 86400);
    }

    public function isUnscoped(): bool
    {
        return $this->scope === null;
    }
}
