<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/redis
 * https://github.com/php-puff/redis/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Redis;

use Puff\Async\Channel;

final class Pool
{
    private Channel $connections;
    private bool $closed = false;

    public function __construct(private readonly Configuration $configuration)
    {
        $this->connections = new Channel($configuration->poolSize);
        for ($index = 0; $index < $configuration->poolSize; ++$index) {
            $this->connections->push(new Connection($configuration));
        }
    }

    public function execute(string $command, string|int|float ...$arguments): mixed
    {
        $deadline = \microtime(true) + $this->configuration->timeout;
        $connection = $this->acquire($deadline);
        try {
            return $connection->executeUntil($deadline, $command, \array_values($arguments));
        } finally {
            $this->release($connection);
        }
    }

    public function withConnection(callable $callback): mixed
    {
        $connection = $this->acquire();
        try {
            return $callback($connection);
        } finally {
            $this->release($connection);
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        while (!$this->connections->isEmpty()) {
            $connection = $this->connections->pop(0);
            if ($connection instanceof Connection) {
                $connection->close();
            }
        }
        $this->connections->close();
    }

    public function size(): int
    {
        return $this->configuration->poolSize;
    }

    public function available(): int
    {
        return $this->connections->length();
    }

    public function __destruct()
    {
        $this->close();
    }

    private function acquire(?float $deadline = null): Connection
    {
        if ($this->closed) {
            throw new RedisConnectionException('Redis connection pool is closed.');
        }
        $timeout = $deadline === null
            ? $this->configuration->timeout
            : $deadline - \microtime(true);
        if ($timeout <= 0) {
            throw new RedisTimeoutException('Timed out waiting for an available Redis connection.');
        }
        $connection = $this->connections->pop($timeout);
        if (!$connection instanceof Connection) {
            throw new RedisTimeoutException('Timed out waiting for an available Redis connection.');
        }
        return $connection;
    }

    private function release(Connection $connection): void
    {
        if ($this->closed || !$this->connections->push($connection)) {
            $connection->close();
        }
    }
}
