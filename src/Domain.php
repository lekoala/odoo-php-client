<?php

namespace LeKoala\OdooClient;

/**
 * A fluent helper for building Odoo search domains in prefix notation (Polish notation).
 */
class Domain
{
    /**
     * @var array<int, mixed>
     */
    protected array $domain = [];

    private function __construct() {}

    /**
     * Entry point to start building a domain.
     */
    public static function build(): self
    {
        return new self();
    }

    /**
     * Add a simple filter (AND).
     *
     * @param string $field Odoo field name
     * @param string $operator Selection operator (=, !=, >, <, ilike, in, etc.)
     * @param mixed $value
     * @return $this
     */
    public function where(string $field, string $operator, $value): self
    {
        $this->domain[] = [$field, $operator, $value];
        return $this;
    }

    /**
     * Add an alternative filter (OR) to the current domain.
     * 
     * Handles wrapping existing conditions in AND if necessary.
     *
     * @param string $field Odoo field name
     * @param string $operator Selection operator
     * @param mixed $value
     * @return $this
     */
    public function orWhere(string $field, string $operator, $value): self
    {
        if (empty($this->domain)) {
            return $this->where($field, $operator, $value);
        }

        $current = $this->domain;

        // If current domain has multiple items, Odoo treats them as implicit ANDs.
        // To OR them correctly, we should explicitly wrap them in '&' if they aren't already.
        // However, a simpler strategy for a linear builder: 
        // Join the WHOLE current domain with the NEW clause via '|'.

        if (count($current) > 1 && $current[0] !== '&' && $current[0] !== '|') {
            // It's a list of leaves, add the implicit ANDs
            $ands = array_fill(0, count($current) - 1, '&');
            $current = array_merge($ands, $current);
        }

        $this->domain = array_merge(['|'], $current, [[$field, $operator, $value]]);

        return $this;
    }

    /**
     * Build a single domain leaf.
     *
     * @param mixed $value
     * @return array{string, string, mixed}
     */
    public static function condition(string $field, string $operator, $value): array
    {
        return [$field, $operator, $value];
    }

    /**
     * Combine domain parts with AND (pure, no builder state).
     *
     * all() → [], all($a) → $a, all($a, $b) → ['&', $a, $b],
     * all($a, $b, $c) → ['&', '&', $a, $b, $c].
     *
     * @param array<int, mixed> ...$parts Leaves or already-combined sub-domains.
     * @return array<int, mixed>
     */
    public static function all(array ...$parts): array
    {
        return self::combine('&', ...$parts);
    }

    /**
     * Combine domain parts with OR (pure, no builder state).
     *
     * any($a, $b, $c) → ['|', '|', $a, $b, $c].
     *
     * @param array<int, mixed> ...$parts Leaves or already-combined sub-domains.
     * @return array<int, mixed>
     */
    public static function any(array ...$parts): array
    {
        return self::combine('|', ...$parts);
    }

    /**
     * @param array<int, mixed> ...$parts
     * @return array<int, mixed>
     */
    private static function combine(string $operator, array ...$parts): array
    {
        $count = count($parts);
        if ($count === 0) {
            return [];
        }
        if ($count === 1) {
            return $parts[0];
        }

        /** @var array<int, mixed> $combined */
        $combined = array_merge(array_fill(0, $count - 1, $operator), $parts);

        return $combined;
    }

    /**
     * Return the domain as a flat Odoo-compatible array.
     * @return array<int, mixed>
     */
    public function get(): array
    {
        return $this->domain;
    }
}
