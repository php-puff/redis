<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/redis
 * https://github.com/php-puff/redis/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Redis\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Puff\Async\EventLoop;
use Puff\Async\Runtime;
use Puff\Redis\Client;
use Puff\Redis\RedisCommandException;

#[RequiresPhpExtension('pcntl')]
final class ClientTest extends TestCase
{
    public function testExecutesCommandOverNonBlockingSocket(): void
    {
        [$server, $port] = $this->server();
        $pid = \pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            $connection = \stream_socket_accept($server, 2);
            if (\is_resource($connection)) {
                \fread($connection, 4096);
                \fwrite($connection, "+PONG\r\n");
                \fclose($connection);
            }
            \fclose($server);
            exit(0);
        }

        EventLoop::reset();
        $client = new Client('127.0.0.1', $port, 1);
        $result = Runtime::run(fn (): mixed => $client->execute('PING'));
        $client->close();
        \pcntl_waitpid($pid, $status);
        \fclose($server);

        self::assertSame('PONG', $result);
        self::assertTrue(\pcntl_wifexited($status));
    }

    public function testUsesMultipleConnectionsForConcurrentFibers(): void
    {
        [$server, $port] = $this->server();
        $pid = \pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            $connections = [];
            for ($index = 0; $index < 2; ++$index) {
                $connection = \stream_socket_accept($server, 2);
                if (\is_resource($connection)) {
                    $connections[] = $connection;
                }
            }
            foreach ($connections as $index => $connection) {
                \fread($connection, 4096);
                \fwrite($connection, '+PONG-' . ($index + 1) . "\r\n");
                \fclose($connection);
            }
            \fclose($server);
            exit(0);
        }

        EventLoop::reset();
        $client = Client::fromConfiguration(new \Puff\Redis\Configuration(
            host: '127.0.0.1',
            port: $port,
            timeout: 1,
            poolSize: 2,
        ));
        $results = Runtime::run(static function () use ($client): array {
            $first = Runtime::async(fn (): mixed => $client->execute('PING', 1));
            $second = Runtime::async(fn (): mixed => $client->execute('PING', 2));
            return [$first->await(), $second->await()];
        });
        $client->close();
        \pcntl_waitpid($pid, $status);
        \fclose($server);

        self::assertEqualsCanonicalizing(['PONG-1', 'PONG-2'], $results);
        self::assertTrue(\pcntl_wifexited($status));
    }

    public function testRejectsStatefulCommandOnSharedClient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires withConnection');
        (new Client())->execute('MULTI');
    }

    public function testCommandErrorDoesNotPoisonConnection(): void
    {
        [$server, $port] = $this->server();
        $pid = \pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            $connection = \stream_socket_accept($server, 2);
            if (\is_resource($connection)) {
                \fread($connection, 4096);
                \fwrite($connection, "-ERR failed\r\n");
                \fread($connection, 4096);
                \fwrite($connection, "+PONG\r\n");
                \fclose($connection);
            }
            \fclose($server);
            exit(0);
        }

        EventLoop::reset();
        $client = Client::fromConfiguration(new \Puff\Redis\Configuration(
            host: '127.0.0.1',
            port: $port,
            timeout: 1,
            poolSize: 1,
        ));
        $result = Runtime::run(static function () use ($client): mixed {
            try {
                $client->execute('INVALID');
                self::fail('Redis error response was accepted.');
            } catch (RedisCommandException) {
                return $client->execute('PING');
            }
        });
        $client->close();
        \pcntl_waitpid($pid, $status);
        \fclose($server);

        self::assertSame('PONG', $result);
        self::assertTrue(\pcntl_wifexited($status));
    }

    /** @return array{resource, int} */
    private function server(): array
    {
        $error = '';
        $server = \stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
        self::assertIsResource($server, (string) $error);
        $address = \stream_socket_get_name($server, false);
        self::assertIsString($address);
        $separator = \strrchr($address, ':');
        self::assertIsString($separator);
        return [$server, (int) \substr($separator, 1)];
    }
}
