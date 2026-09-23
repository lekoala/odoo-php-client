<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Domain;
use PHPUnit\Framework\TestCase;

class DomainTest extends TestCase
{
    public function testSimpleWhere(): void
    {
        $domain = Domain::build()->where('state', '=', 'posted')->get();
        self::assertEquals([['state', '=', 'posted']], $domain);
    }

    public function testMultipleWhere(): void
    {
        $domain = Domain::build()
            ->where('state', '=', 'posted')
            ->where('move_type', '=', 'out_invoice')
            ->get();
            
        self::assertEquals([
            ['state', '=', 'posted'],
            ['move_type', '=', 'out_invoice']
        ], $domain);
    }

    public function testOrWhere(): void
    {
        $domain = Domain::build()
            ->where('is_company', '=', true)
            ->orWhere('name', 'ilike', 'Azure')
            ->get();
            
        self::assertEquals([
            '|',
            ['is_company', '=', true],
            ['name', 'ilike', 'Azure']
        ], $domain);
    }

    public function testComplexNesting(): void
    {
        // (A AND B) OR C
        $domain = Domain::build()
            ->where('A', '=', 1)
            ->where('B', '=', 2)
            ->orWhere('C', '=', 3)
            ->get();
            
        self::assertEquals([
            '|',
            '&',
            ['A', '=', 1],
            ['B', '=', 2],
            ['C', '=', 3]
        ], $domain);
        
        // ((A AND B) OR C) AND D
        $domain[] = ['D', '=', 4]; // Test adding manual AND after builder
        // Odoo will treat this correctly as it parses prefix notation.
    }
    
    public function testNestingWithWhereAfterOr(): void
    {
        // ((A OR B) AND C)
        $domain = Domain::build()
            ->where('A', '=', 1)
            ->orWhere('B', '=', 2)
            ->where('C', '=', 3)
            ->get();
            
        self::assertEquals([
            '|',
            ['A', '=', 1],
            ['B', '=', 2],
            ['C', '=', 3]
        ], $domain);
        // Note: Odoo treats [OR, A, B, C] as (A OR B) AND C 
        // because the top-level is implicit AND.
    }

    public function testChainedOrWhereStaysLeftAssociative(): void
    {
        // (A OR B) OR C
        $domain = Domain::build()
            ->where('A', '=', 1)
            ->orWhere('B', '=', 2)
            ->orWhere('C', '=', 3)
            ->get();

        self::assertEquals([
            '|',
            '|',
            ['A', '=', 1],
            ['B', '=', 2],
            ['C', '=', 3]
        ], $domain);
    }

    public function testOrWhereAfterAnAndBlockStaysLeftAssociative(): void
    {
        // ((A AND B) OR C) OR D
        $domain = Domain::build()
            ->where('A', '=', 1)
            ->where('B', '=', 2)
            ->orWhere('C', '=', 3)
            ->orWhere('D', '=', 4)
            ->get();

        self::assertEquals([
            '|',
            '|',
            '&',
            ['A', '=', 1],
            ['B', '=', 2],
            ['C', '=', 3],
            ['D', '=', 4]
        ], $domain);
    }

    public function testOrWhereOnAnEmptyDomainBehavesLikeWhere(): void
    {
        $domain = Domain::build()
            ->orWhere('A', '=', 1)
            ->get();

        self::assertEquals([['A', '=', 1]], $domain);
    }

    public function testConditionBuildsALeaf(): void
    {
        self::assertEquals(
            ['state', '=', 'posted'],
            Domain::condition('state', '=', 'posted')
        );
    }

    public function testAllContract(): void
    {
        self::assertSame([], Domain::all());

        $a = Domain::condition('a', '=', 1);
        self::assertSame($a, Domain::all($a));

        $b = Domain::condition('b', '=', 2);
        self::assertEquals(['&', $a, $b], Domain::all($a, $b));

        $c = Domain::condition('c', '=', 3);
        self::assertEquals(['&', '&', $a, $b, $c], Domain::all($a, $b, $c));
    }

    public function testAnyContract(): void
    {
        self::assertSame([], Domain::any());

        $a = Domain::condition('a', '=', 1);
        self::assertSame($a, Domain::any($a));

        $b = Domain::condition('b', '=', 2);
        $c = Domain::condition('c', '=', 3);
        self::assertEquals(['|', '|', $a, $b, $c], Domain::any($a, $b, $c));
    }

    public function testNestedAllOfAny(): void
    {
        // state = posted AND (payment_state = partial OR payment_state = not_paid)
        $domain = Domain::all(
            Domain::condition('state', '=', 'posted'),
            Domain::any(
                Domain::condition('payment_state', '=', 'partial'),
                Domain::condition('payment_state', '=', 'not_paid'),
            ),
        );

        self::assertEquals([
            '&',
            ['state', '=', 'posted'],
            ['|', ['payment_state', '=', 'partial'], ['payment_state', '=', 'not_paid']],
        ], $domain);
    }

    public function testExplicitCombinatorsAvoidWhereAfterOrAmbiguity(): void
    {
        // Explicit: (A OR B) AND C — no reliance on implicit top-level AND.
        $domain = Domain::all(
            Domain::any(
                Domain::condition('A', '=', 1),
                Domain::condition('B', '=', 2),
            ),
            Domain::condition('C', '=', 3),
        );

        self::assertEquals([
            '&',
            ['|', ['A', '=', 1], ['B', '=', 2]],
            ['C', '=', 3],
        ], $domain);
    }
}
