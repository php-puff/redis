<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/redis
 * https://github.com/php-puff/redis/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Redis;

use Puff\Config\Config;
use Puff\Di\ServiceProvider as BaseServiceProvider;

final class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->alias(Client::class, 'redis.client');
        $this->app->alias(Client::class, ClientInterface::class);
        $this->app->singleton(Client::class, function (): Client {
            $config = $this->configuration();
            $values = $config?->get('redis.default', []) ?? [];
            if (!\is_array($values)) {
                throw new RedisException('Redis configuration [redis.default] must be an array.');
            }
            return Client::fromConfiguration(Configuration::fromArray($values));
        });
    }

    private function configuration(): ?Config
    {
        if (!$this->app->bound('config')) {
            return null;
        }
        $config = $this->app->make('config');
        if (!$config instanceof Config) {
            throw new RedisException('The config service must be a ' . Config::class . ' instance.');
        }
        return $config;
    }
}
