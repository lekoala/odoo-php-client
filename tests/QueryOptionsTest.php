<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\QueryOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @covers \LeKoala\OdooClient\QueryOptions */
final class QueryOptionsTest extends TestCase
{
    public function testIntegerCompatibilityCreatesALimitedQuery(): void
    {
        $options = QueryOptions::from(80);

        self::assertSame(80, $options->limit);
        self::assertSame(80, $options->searchReadLimit());
    }

    public function testNullLimitMeansUnboundedSearchRead(): void
    {
        self::assertSame(0, (new QueryOptions())->searchReadLimit());
    }

    #[DataProvider('orders')]
    public function testStableOrder(string|null $order, string $expected): void
    {
        self::assertSame($expected, (new QueryOptions(order: $order))->stableOrder());
    }

    /** @return array<string, array{string|null, string}> */
    public static function orders(): array
    {
        return [
            'default' => [null, 'id asc'],
            'custom gets tiebreaker' => ['name asc', 'name asc, id asc'],
            'existing id ascending' => ['name asc, id asc', 'name asc, id asc'],
            'existing id descending' => ['id desc', 'id desc'],
        ];
    }

    public function testRejectsInvalidPaginationValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QueryOptions(batchSize: 0);
    }
}
