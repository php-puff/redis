<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/redis
 * https://github.com/php-puff/redis/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Redis;

final readonly class Configuration
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 6379,
        public float $timeout = 5.0,
        public ?string $password = null,
        public int $database = 0,
        public int $poolSize = 10,
        public ?string $username = null,
        public string $scheme = 'tcp',
        public int $maxResponseSize = 16_777_216,
        public int $maxBulkLength = 16_777_216,
        public int $maxArrayLength = 100_000,
        public int $maxDepth = 32,
        public bool $verifyPeer = true,
        public ?string $peerName = null,
    ) {
        if ($host === '') {
            throw new \InvalidArgumentException('Redis host must not be empty.');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Redis port must be between 1 and 65535.');
        }
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('Redis timeout must be greater than zero.');
        }
        if ($database < 0) {
            throw new \InvalidArgumentException('Redis database must not be negative.');
        }
        if ($username !== null && $password === null) {
            throw new \InvalidArgumentException('Redis ACL username requires a password.');
        }
        if ($poolSize < 1) {
            throw new \InvalidArgumentException('Redis pool size must be at least one.');
        }
        if (!\in_array($scheme, ['tcp', 'tls', 'unix'], true)) {
            throw new \InvalidArgumentException("Unsupported Redis scheme [{$scheme}].");
        }
        if ($maxResponseSize < 1 || $maxBulkLength < 1 || $maxArrayLength < 1 || $maxDepth < 1) {
            throw new \InvalidArgumentException('Redis protocol limits must be greater than zero.');
        }
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self(
            host: self::string($values, 'host', '127.0.0.1'),
            port: self::integer($values, 'port', 6379),
            timeout: self::number($values, 'timeout', 5.0),
            password: self::nullableString($values, 'password'),
            database: self::integer($values, 'database', 0),
            poolSize: self::integer($values, 'pool', 10),
            username: self::nullableString($values, 'username'),
            scheme: self::string($values, 'scheme', 'tcp'),
            maxResponseSize: self::integer($values, 'max_response_size', 16_777_216),
            maxBulkLength: self::integer($values, 'max_bulk_length', 16_777_216),
            maxArrayLength: self::integer($values, 'max_array_length', 100_000),
            maxDepth: self::integer($values, 'max_depth', 32),
            verifyPeer: self::boolean($values, 'verify_peer', true),
            peerName: self::nullableString($values, 'peer_name'),
        );
    }

    public function uri(): string
    {
        if ($this->scheme === 'unix') {
            return 'unix://' . $this->host;
        }
        $host = \str_contains($this->host, ':') ? '[' . \trim($this->host, '[]') . ']' : $this->host;
        return "{$this->scheme}://{$host}:{$this->port}";
    }

    /** @param array<string, mixed> $values */
    private static function string(array $values, string $key, string $default): string
    {
        $value = $values[$key] ?? $default;
        if (!\is_string($value) || $value === '') {
            throw new \InvalidArgumentException("Redis configuration [{$key}] must be a non-empty string.");
        }
        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function nullableString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!\is_string($value)) {
            throw new \InvalidArgumentException("Redis configuration [{$key}] must be a string or null.");
        }
        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function integer(array $values, string $key, int $default): int
    {
        $value = $values[$key] ?? $default;
        if (!\is_int($value)) {
            throw new \InvalidArgumentException("Redis configuration [{$key}] must be an integer.");
        }
        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function number(array $values, string $key, float $default): float
    {
        $value = $values[$key] ?? $default;
        if (!\is_int($value) && !\is_float($value)) {
            throw new \InvalidArgumentException("Redis configuration [{$key}] must be numeric.");
        }
        return (float) $value;
    }

    /** @param array<string, mixed> $values */
    private static function boolean(array $values, string $key, bool $default): bool
    {
        $value = $values[$key] ?? $default;
        if (!\is_bool($value)) {
            throw new \InvalidArgumentException("Redis configuration [{$key}] must be boolean.");
        }
        return $value;
    }
}
