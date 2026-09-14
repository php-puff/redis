<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/redis
 * https://github.com/php-puff/redis/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Redis\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Puff\Redis\RedisCommandException;
use Puff\Redis\RedisProtocolException;
use Puff\Redis\RespParser;

final class RespParserTest extends TestCase
{
    public function testParsesNestedRespValues(): void
    {
        $payload = "*4\r\n+OK\r\n:42\r\n$5\r\nhello\r\n$-1\r\n";
        [$complete, $value, $consumed] = RespParser::parse($payload);

        self::assertTrue($complete);
        self::assertSame(['OK', 42, 'hello', null], $value);
        self::assertSame(\strlen($payload), $consumed);
    }

    public function testReportsIncompletePayload(): void
    {
        self::assertSame([false, null, 0], RespParser::parse("$5\r\nabc"));
    }

    public function testParsesRedisErrorWithoutLosingFrameLength(): void
    {
        $payload = "-ERR invalid command\r\n";
        [$complete, $value, $consumed] = RespParser::parse($payload);

        self::assertTrue($complete);
        self::assertInstanceOf(RedisCommandException::class, $value);
        self::assertSame(\strlen($payload), $consumed);
    }

    public function testPreservesErrorsInsideArrayResponses(): void
    {
        [, $value] = RespParser::parse("*2\r\n+OK\r\n-ERR failed\r\n");

        self::assertIsArray($value);
        self::assertSame('OK', $value[0]);
        self::assertInstanceOf(RedisCommandException::class, $value[1]);
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsMalformedPayload(string $payload): void
    {
        $this->expectException(RedisProtocolException::class);
        RespParser::parse($payload);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'invalid integer' => [":abc\r\n"];
        yield 'negative bulk length' => ["$-2\r\n"];
        yield 'invalid bulk terminator' => ["$3\r\nabcxx"];
        yield 'negative array length' => ["*-2\r\n"];
        yield 'unsupported type' => ["!value\r\n"];
    }

    public function testEnforcesProtocolLimits(): void
    {
        $this->expectException(RedisProtocolException::class);
        RespParser::parse("$6\r\nabcdef\r\n", maxBulkLength: 5);
    }
}
