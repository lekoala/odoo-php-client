<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\OdooValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OdooValue::class)]
class OdooValueTest extends TestCase
{
    public function testIdKeepsPositiveIdsOnly(): void
    {
        self::assertSame(42, OdooValue::id(42));
        self::assertSame(42, OdooValue::id('42'));
        self::assertNull(OdooValue::id(0));
        self::assertNull(OdooValue::id(-3));
        self::assertNull(OdooValue::id(null));
        self::assertNull(OdooValue::id(false));
        self::assertNull(OdooValue::id(''));
        self::assertNull(OdooValue::id([42, 'Alice']));
    }

    public function testMany2oneIdDecodesOdooShapes(): void
    {
        self::assertSame(42, OdooValue::many2oneId([42, 'Alice']));
        self::assertSame(42, OdooValue::many2oneId(42));
        self::assertNull(OdooValue::many2oneId(false));
        self::assertNull(OdooValue::many2oneId(null));
        self::assertNull(OdooValue::many2oneId(''));
        self::assertNull(OdooValue::many2oneId([false, '']));
        self::assertNull(OdooValue::many2oneId(0));
        self::assertSame(7, OdooValue::many2oneIdOr(false, 7));
        self::assertSame(42, OdooValue::many2oneIdOr([42, 'Alice'], 7));
    }

    public function testMany2oneNameDecodesOdooShapes(): void
    {
        self::assertSame('Alice', OdooValue::many2oneName([42, 'Alice']));
        self::assertNull(OdooValue::many2oneName(42));
        self::assertNull(OdooValue::many2oneName(false));
        self::assertNull(OdooValue::many2oneName(null));
    }

    public function testIdsKeepsPositiveIdsOnly(): void
    {
        self::assertSame([1, 2], OdooValue::ids([1, 2]));
        self::assertSame([], OdooValue::ids(false));
        self::assertSame([], OdooValue::ids(null));
        self::assertSame([], OdooValue::ids('nope'));
        self::assertSame([3], OdooValue::ids([0, 3, -1]));
    }

    public function testNullableStringNormalizesFalseAndNull(): void
    {
        self::assertNull(OdooValue::nullableString(false));
        self::assertNull(OdooValue::nullableString(null));
        self::assertSame('', OdooValue::nullableString(''));
        self::assertSame('posted', OdooValue::nullableString('posted'));
        self::assertNull(OdooValue::nullableString(42));
    }

    public function testScalarHelpers(): void
    {
        self::assertSame('draft', OdooValue::stringOr(null, 'draft'));
        self::assertSame('', OdooValue::stringOr(false));
        self::assertSame('x', OdooValue::stringOr('x', 'draft'));
        self::assertSame(29.9, OdooValue::floatOr('29.9'));
        self::assertSame(0.0, OdooValue::floatOr(false));
        self::assertTrue(OdooValue::bool('x'));
        self::assertFalse(OdooValue::bool(false));
        self::assertFalse(OdooValue::bool(null));
    }
}
