<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Config;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LeKoala\OdooClient\Config
 */
class ConfigTest extends TestCase
{
    public function testRetryAndTimeoutDefaults(): void
    {
        $config = new Config('http://odoo.local', 'test_db', 'api_key');

        self::assertSame(2, $config->getMaxRetries());
        self::assertSame(500, $config->getRetryBackoffBaseMs());
        self::assertSame(2.0, $config->getRetryBackoffMultiplier());
        self::assertSame(5, $config->getConnectTimeout());
        self::assertSame(30, $config->getTimeout());
        self::assertSame(5, $config->getMaxRedirects());
    }

    public function testRetryAndTimeoutSettersClampInvalidValues(): void
    {
        $config = (new Config('http://odoo.local', 'test_db', 'api_key'))
            ->setMaxRetries(-1)
            ->setRetryBackoff(-10, 0.5)
            ->setConnectTimeout(0)
            ->setTimeout(0)
            ->setMaxRedirects(-3);

        self::assertSame(0, $config->getMaxRetries());
        self::assertSame(0, $config->getRetryBackoffBaseMs());
        self::assertSame(1.0, $config->getRetryBackoffMultiplier());
        self::assertSame(1, $config->getConnectTimeout());
        self::assertSame(1, $config->getTimeout());
        self::assertSame(0, $config->getMaxRedirects());
    }

    public function testWithApiKeyPreservesRetryAndTimeoutSettings(): void
    {
        $config = (new Config('http://odoo.local', 'test_db', 'old'))
            ->setMaxRetries(4)
            ->setRetryBackoff(100, 3.0)
            ->setConnectTimeout(7)
            ->setTimeout(60)
            ->setMaxRedirects(2);

        $clone = $config->withApiKey('new');

        self::assertSame('new', $clone->getApiKey());
        self::assertSame(4, $clone->getMaxRetries());
        self::assertSame(100, $clone->getRetryBackoffBaseMs());
        self::assertSame(3.0, $clone->getRetryBackoffMultiplier());
        self::assertSame(7, $clone->getConnectTimeout());
        self::assertSame(60, $clone->getTimeout());
        self::assertSame(2, $clone->getMaxRedirects());
    }

    public function testFromArrayReadsRetryAndTimeoutSettings(): void
    {
        $config = Config::fromArray([
            'url' => 'http://odoo.local',
            'db' => 'test_db',
            'apiKey' => 'api_key',
            'maxRetries' => 5,
            'retryBackoffBaseMs' => 250,
            'retryBackoffMultiplier' => 1.5,
            'connectTimeout' => 3,
            'timeout' => 10,
            'maxRedirects' => 1,
        ]);

        self::assertSame(5, $config->getMaxRetries());
        self::assertSame(250, $config->getRetryBackoffBaseMs());
        self::assertSame(1.5, $config->getRetryBackoffMultiplier());
        self::assertSame(3, $config->getConnectTimeout());
        self::assertSame(10, $config->getTimeout());
        self::assertSame(1, $config->getMaxRedirects());
    }
}
