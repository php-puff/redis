<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/redis
 * https://github.com/php-puff/redis/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

return [
    'default' => [
        'scheme' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 6379,
        'username' => null,
        'password' => null,
        'database' => 0,
        'timeout' => 5.0,
        'pool' => 10,
        'verify_peer' => true,
        'peer_name' => null,
        'max_response_size' => 16_777_216,
        'max_bulk_length' => 16_777_216,
        'max_array_length' => 100_000,
        'max_depth' => 32,
    ],
];
