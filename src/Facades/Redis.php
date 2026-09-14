<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/redis
 * https://github.com/php-puff/redis/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Redis\Facades;

use Generator;
use Puff\Di\Facade;
use Puff\Redis\Client;
use Puff\Redis\Configuration;
use Puff\Redis\Pool;

/**
 * @method static mixed execute(string $command, string|int|float ...$arguments)
 * @method static mixed withConnection(callable $callback)
 * @method static array<int, mixed>|null transaction(callable $callback)
 * @method static bool has(string $key)
 * @method static string|null get(string $key)
 * @method static bool set(string $key, string $value, int $ttl = 0)
 * @method static array<string, string|null> getMultiple(array<int, string> $keys)
 * @method static bool setMultiple(array<string, string|int|float> $values)
 * @method static int increment(string $key, int $value = 1)
 * @method static float incrementByFloat(string $key, float $value)
 * @method static int decrement(string $key, int $value = 1)
 * @method static int delete(string ...$keys)
 * @method static Generator<int, string> scan(string $pattern = '*', int $count = 100)
 * @method static array<int, string> keys(string $pattern = '*')
 * @method static Configuration configuration()
 * @method static Pool pool()
 * @method static void close()
 */
final class Redis extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
