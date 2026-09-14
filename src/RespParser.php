<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/redis
 * https://github.com/php-puff/redis/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Redis;

final class RespParser
{
    /** @return array{bool, mixed, int} */
    public static function parse(
        string $buffer,
        int $maxBulkLength = 16_777_216,
        int $maxArrayLength = 100_000,
        int $maxDepth = 32,
    ): array {
        $offset = 0;
        [$complete, $value] = self::value($buffer, $offset, 0, $maxBulkLength, $maxArrayLength, $maxDepth);
        return $complete ? [true, $value, $offset] : [false, null, 0];
    }

    /** @return array{bool, mixed} */
    private static function value(
        string $buffer,
        int &$offset,
        int $depth,
        int $maxBulkLength,
        int $maxArrayLength,
        int $maxDepth,
    ): array {
        if ($depth > $maxDepth) {
            throw new RedisProtocolException('RESP nesting depth exceeds the configured limit.');
        }
        if (!isset($buffer[$offset])) {
            return [false, null];
        }
        $type = $buffer[$offset++];
        if ($type === '+' || $type === '-' || $type === ':') {
            $line = self::line($buffer, $offset);
            if ($line === null) {
                return [false, null];
            }
            if ($type === '-') {
                return [true, new RedisCommandException($line)];
            }
            if ($type === ':') {
                $integer = \filter_var($line, FILTER_VALIDATE_INT);
                if ($integer === false) {
                    throw new RedisProtocolException("Invalid RESP integer [{$line}].");
                }
                return [true, $integer];
            }
            return [true, $line];
        }

        if ($type === '$') {
            $line = self::line($buffer, $offset);
            if ($line === null) {
                return [false, null];
            }
            if ($line === '-1') {
                return [true, null];
            }
            $length = self::length($line, 'bulk', $maxBulkLength);
            $end = $offset + $length;
            if (\strlen($buffer) < $end + 2) {
                return [false, null];
            }
            if (\substr($buffer, $end, 2) !== "\r\n") {
                throw new RedisProtocolException('RESP bulk string is not terminated by CRLF.');
            }
            $value = \substr($buffer, $offset, $length);
            $offset = $end + 2;
            return [true, $value];
        }

        if ($type === '*') {
            $line = self::line($buffer, $offset);
            if ($line === null) {
                return [false, null];
            }
            if ($line === '-1') {
                return [true, null];
            }
            $count = self::length($line, 'array', $maxArrayLength);
            $values = [];
            for ($index = 0; $index < $count; ++$index) {
                [$complete, $value] = self::value(
                    $buffer,
                    $offset,
                    $depth + 1,
                    $maxBulkLength,
                    $maxArrayLength,
                    $maxDepth,
                );
                if (!$complete) {
                    return [false, null];
                }
                $values[] = $value;
            }
            return [true, $values];
        }

        throw new RedisProtocolException('Unsupported RESP type byte: ' . \bin2hex($type));
    }

    private static function length(string $line, string $type, int $maximum): int
    {
        if (\preg_match('/^(?:0|[1-9][0-9]*)$/D', $line) !== 1) {
            throw new RedisProtocolException("Invalid RESP {$type} length [{$line}].");
        }
        $length = \filter_var($line, FILTER_VALIDATE_INT);
        if ($length === false || $length > $maximum) {
            throw new RedisProtocolException("RESP {$type} length exceeds the configured limit.");
        }
        return $length;
    }

    private static function line(string $buffer, int &$offset): ?string
    {
        $end = \strpos($buffer, "\r\n", $offset);
        if ($end === false) {
            return null;
        }
        $line = \substr($buffer, $offset, $end - $offset);
        $offset = $end + 2;
        return $line;
    }
}
