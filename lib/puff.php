<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/redis
 * https://github.com/php-puff/redis/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

use Puff\Di\Container;
use Puff\Redis\Client;

if (!\function_exists('redis')) {
    function redis(): Client
    {
        $container = Container::getInstance();
        if ($container === null) {
            throw new LogicException('The Puff container has not been initialized.');
        }
        if (!$container->bound(Client::class)) {
            throw new LogicException('The Redis client service is not registered.');
        }
        $client = $container->get(Client::class);
        if (!$client instanceof Client) {
            throw new LogicException('The Redis client service is invalid.');
        }
        return $client;
    }
}
