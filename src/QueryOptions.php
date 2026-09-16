<?php

namespace LeKoala\OdooClient;

/**
 * Options shared by typed service queries.
 *
 * A null or zero limit means that no overall result limit is applied. Iterators
 * still fetch bounded pages using batchSize.
 */
final readonly class QueryOptions
{
    /**
     * @param array<int, string> $fields Empty means that the service's default fields are used.
     * @param array<string, mixed> $context
     */
    public function __construct(
        public ?int $limit = null,
        public int $offset = 0,
        public ?string $order = null,
        public array $fields = [],
        public array $context = [],
        public int $batchSize = 80,
    ) {
        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException('Query limit cannot be negative');
        }
        if ($offset < 0) {
            throw new \InvalidArgumentException('Query offset cannot be negative');
        }
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('Query batch size must be at least 1');
        }
    }

    public static function from(int|self|null $options): self
    {
        if ($options instanceof self) {
            return $options;
        }

        return new self(limit: $options);
    }

    public function withOrder(?string $order): self
    {
        return new self(
            limit: $this->limit,
            offset: $this->offset,
            order: $order,
            fields: $this->fields,
            context: $this->context,
            batchSize: $this->batchSize,
        );
    }

    public function searchReadLimit(): int
    {
        return $this->limit ?? 0;
    }

    public function stableOrder(): string
    {
        $order = trim($this->order ?? '');
        if ($order === '') {
            return 'id asc';
        }

        foreach (explode(',', $order) as $clause) {
            if (preg_match('/^\s*id(?:\s|$)/i', $clause) === 1) {
                return $order;
            }
        }

        return $order . ', id asc';
    }
}
