<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/redis
 * https://github.com/php-puff/redis/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Redis;

interface ClientInterface
{
    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttl = 0): bool;

    public function delete(string ...$keys): int;
}
