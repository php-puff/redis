<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/redis
 * https://github.com/php-puff/redis/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Redis;

use Generator;
use Throwable;

final class Client implements ClientInterface
{
    private const DEDICATED_COMMANDS = [
        'AUTH', 'SELECT', 'MULTI', 'EXEC', 'DISCARD', 'WATCH', 'UNWATCH',
        'SUBSCRIBE', 'PSUBSCRIBE', 'SSUBSCRIBE', 'UNSUBSCRIBE', 'PUNSUBSCRIBE', 'SUNSUBSCRIBE',
        'MONITOR', 'BLPOP', 'BRPOP', 'BRPOPLPUSH', 'BLMOVE', 'BLMPOP', 'BZPOPMIN', 'BZPOPMAX', 'BZMPOP',
        'QUIT',
    ];

    private readonly Pool $pool;
    private readonly Configuration $configuration;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        float $timeout = 5.0,
        ?string $password = null,
        int $database = 0,
        ?Configuration $configuration = null,
    ) {
        $this->configuration = $configuration ?? new Configuration($host, $port, $timeout, $password, $database);
        $this->pool = new Pool($this->configuration);
    }

    public static function fromConfiguration(Configuration $configuration): self
    {
        return new self(configuration: $configuration);
    }

    public function execute(string $command, string|int|float ...$arguments): mixed
    {
        $command = \strtoupper(\trim($command));
        if (\in_array($command, self::DEDICATED_COMMANDS, true)) {
            throw new \InvalidArgumentException(
                "Redis command [{$command}] requires withConnection(), transaction(), or a dedicated subscriber.",
            );
        }
        return $this->pool->execute($command, ...$arguments);
    }

    public function withConnection(callable $callback): mixed
    {
        return $this->pool->withConnection($callback);
    }

    /** @return list<mixed>|null */
    public function transaction(callable $callback): ?array
    {
        return $this->pool->withConnection(static function (Connection $connection) use ($callback): ?array {
            $connection->execute('MULTI');
            try {
                $callback($connection);
                $result = $connection->execute('EXEC');
                if ($result !== null && !\is_array($result)) {
                    throw new RedisProtocolException('Redis EXEC returned an invalid response.');
                }
                return $result;
            } catch (Throwable $exception) {
                try {
                    $connection->execute('DISCARD');
                } catch (Throwable) {
                    $connection->close();
                }
                throw $exception;
            }
        });
    }

    public function has(string $key): bool
    {
        return (int) $this->execute('EXISTS', $key) > 0;
    }

    public function get(string $key): ?string
    {
        $value = $this->execute('GET', $key);
        if ($value !== null && !\is_string($value)) {
            throw new RedisProtocolException('Redis GET returned an invalid response.');
        }
        return $value;
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        if ($ttl < 0) {
            throw new \InvalidArgumentException('Redis TTL must not be negative.');
        }
        $result = $ttl > 0
            ? $this->execute('SET', $key, $value, 'EX', $ttl)
            : $this->execute('SET', $key, $value);
        return $result === 'OK';
    }

    /** @param list<string> $keys
     * @return array<string, string|null>
     */
    public function getMultiple(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $values = $this->execute('MGET', ...$keys);
        if (!\is_array($values) || \count($values) !== \count($keys)) {
            throw new RedisProtocolException('Redis MGET returned an invalid response.');
        }
        $result = [];
        foreach ($keys as $index => $key) {
            $value = $values[$index];
            if ($value !== null && !\is_string($value)) {
                throw new RedisProtocolException('Redis MGET returned an invalid value.');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /** @param array<string, string|int|float> $values */
    public function setMultiple(array $values): bool
    {
        if ($values === []) {
            return true;
        }
        $arguments = [];
        foreach ($values as $key => $value) {
            $arguments[] = $key;
            $arguments[] = $value;
        }
        return $this->execute('MSET', ...$arguments) === 'OK';
    }

    public function increment(string $key, int $value = 1): int
    {
        return (int) $this->execute('INCRBY', $key, $value);
    }

    public function incrementByFloat(string $key, float $value): float
    {
        return (float) $this->execute('INCRBYFLOAT', $key, $value);
    }

    public function decrement(string $key, int $value = 1): int
    {
        return (int) $this->execute('DECRBY', $key, $value);
    }

    public function delete(string ...$keys): int
    {
        return $keys === [] ? 0 : (int) $this->execute('DEL', ...$keys);
    }

    /** @return Generator<int, string> */
    public function scan(string $pattern = '*', int $count = 100): Generator
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Redis SCAN count must be greater than zero.');
        }
        $cursor = '0';
        do {
            $response = $this->execute('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', $count);
            if (!\is_array($response) || \count($response) !== 2 || !\is_string($response[0]) || !\is_array($response[1])) {
                throw new RedisProtocolException('Redis SCAN returned an invalid response.');
            }
            $cursor = $response[0];
            foreach ($response[1] as $key) {
                if (!\is_string($key)) {
                    throw new RedisProtocolException('Redis SCAN returned an invalid key.');
                }
                yield $key;
            }
        } while ($cursor !== '0');
    }

    /** @return list<string> */
    public function keys(string $pattern = '*'): array
    {
        return \iterator_to_array($this->scan($pattern), false);
    }

    public function configuration(): Configuration
    {
        return $this->configuration;
    }

    public function pool(): Pool
    {
        return $this->pool;
    }

    public function close(): void
    {
        $this->pool->close();
    }
}
