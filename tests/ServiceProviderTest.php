<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/redis
 * https://github.com/php-puff/redis/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Redis\Tests;

use PHPUnit\Framework\TestCase;
use Puff\Config\Config;
use Puff\Di\Container;
use Puff\Redis\Client;
use Puff\Redis\ClientInterface;
use Puff\Redis\Facades\Redis as RedisFacade;
use Puff\Redis\RedisException;
use Puff\Redis\ServiceProvider;

final class ServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance();
    }

    public function testRegistersConfiguredClientAndHelper(): void
    {
        $container = new Container();
        $container->instance('config', new Config(['redis' => ['default' => [
            'host' => '::1',
            'port' => 6380,
            'timeout' => 1.0,
            'pool' => 2,
        ]]]));
        (new ServiceProvider($container))->register();
        Container::setInstance($container);

        $client = \redis();
        self::assertSame($client, $container->get(Client::class));
        self::assertSame($client, $container->get(ClientInterface::class));
        self::assertSame('tcp://[::1]:6380', $client->configuration()->uri());
        self::assertSame(2, $client->pool()->size());
        $client->close();
    }

    public function testRejectsInvalidConfigService(): void
    {
        $container = new Container();
        $container->instance('config', 'invalid');
        (new ServiceProvider($container))->register();

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('config service must be');
        $container->get(Client::class);
    }

    public function testFacadeResolvesRegisteredClient(): void
    {
        $container = new Container();
        (new ServiceProvider($container))->register();
        Container::setInstance($container);

        $client = $container->get(Client::class);

        self::assertSame($client, RedisFacade::getFacadeRoot());
        self::assertSame('tcp://127.0.0.1:6379', RedisFacade::configuration()->uri());
        $client->close();
    }
}
