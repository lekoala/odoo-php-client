<?php

namespace LeKoala\OdooClient\Models;

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
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $scope = $row['scope'] ?? null;
        $expiry = $row['expiration_date'] ?? null;

        return new self(
            isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0,
            isset($row['name']) && is_string($row['name']) ? $row['name'] : '',
            (is_string($scope) && $scope !== '') ? $scope : null,
            (is_string($expiry) && $expiry !== '') ? $expiry : null
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
