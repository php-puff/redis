<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/redis
 * https://github.com/php-puff/redis/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Redis\Tests;

use PHPUnit\Framework\TestCase;
use Puff\Redis\Configuration;

final class ConfigurationTest extends TestCase
{
    public function testBuildsIpv4Ipv6AndUnixUris(): void
    {
        self::assertSame('tcp://127.0.0.1:6379', (new Configuration())->uri());
        self::assertSame('tcp://[::1]:6379', (new Configuration(host: '::1'))->uri());
        self::assertSame('unix:///tmp/redis.sock', (new Configuration(host: '/tmp/redis.sock', scheme: 'unix'))->uri());
    }

    public function testValidatesConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Configuration(poolSize: 0);
    }

    public function testPackagePublishesConfiguration(): void
    {
        $manifest = \json_decode(
            (string) \file_get_contents(\dirname(__DIR__) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $configuration = require \dirname(__DIR__) . '/config/redis.php';

        self::assertSame(['redis.php' => 'config/redis.php'], $manifest['extra']['puff']['config'] ?? null);
        self::assertIsArray($configuration['default'] ?? null);
        self::assertSame(10, $configuration['default']['pool'] ?? null);
    }
}
