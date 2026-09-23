<?php

namespace LeKoala\OdooClient;

/**
 * Decodes Odoo wire-format conventions into PHP scalars.
 *
 * Odoo represents empty relational fields as `false`, many2one values as
 * `[id, displayName]` pairs (or a bare id), and to-many relations as lists
 * of ids. This helper normalizes those shapes only; DTO defaults and
 * business rules stay in the DTOs.
 */
final class OdooValue
{
    public static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public static function many2oneId(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public static function many2oneIdOr(mixed $value, int $default): int
    {
        return self::many2oneId($value) ?? $default;
    }

    public static function many2oneName(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[1] ?? null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<int>
     */
    public static function ids(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            if (is_numeric($item) && (int) $item > 0) {
                $ids[(int) $item] = (int) $item;
            }
        }

        return array_values($ids);
    }

    public static function nullableString(mixed $value): ?string
    {
        if ($value === false || $value === null) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    public static function stringOr(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    public static function floatOr(mixed $value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    public static function bool(mixed $value): bool
    {
        return !empty($value);
    }
}
