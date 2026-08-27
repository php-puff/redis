<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/redis
 * https://github.com/php-puff/redis/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Redis;

use Puff\Async\DeferredFuture;
use Puff\Async\EventLoop;
use Throwable;

final class Connection
{
    /** @var resource|null */
    private $stream = null;
    private string $readBuffer = '';
    private ?int $pid = null;

    public function __construct(private readonly Configuration $configuration)
    {
    }

    public function execute(string $command, string|int|float ...$arguments): mixed
    {
        return $this->executeUntil(
            \microtime(true) + $this->configuration->timeout,
            $command,
            \array_values($arguments),
        );
    }

    /** @param list<string|int|float> $arguments */
    public function executeUntil(float $deadline, string $command, array $arguments): mixed
    {
        $this->ensureBefore($deadline);
        $command = \strtoupper(\trim($command));
        if ($command === '') {
            throw new \InvalidArgumentException('Redis command must not be empty.');
        }
        try {
            $this->connect($deadline);
            return $this->raw($command, $arguments, $deadline);
        } catch (RedisCommandException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->close();
            throw $exception;
        }
    }

    public function close(): void
    {
        if (\is_resource($this->stream)) {
            @\fclose($this->stream);
        }
        $this->stream = null;
        $this->readBuffer = '';
        $this->pid = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function connect(float $deadline): void
    {
        $pid = \getmypid();
        if ($pid === false) {
            throw new RedisConnectionException('Unable to determine the current process ID.');
        }
        if (\is_resource($this->stream) && $this->pid === $pid) {
            return;
        }
        $this->close();
        $uri = $this->configuration->uri();
        $context = \stream_context_create($this->streamOptions());
        $stream = @\stream_socket_client(
            $uri,
            $errorCode,
            $errorMessage,
            0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context,
        );
        if (!\is_resource($stream)) {
            throw new RedisConnectionException("Unable to connect to Redis: {$errorMessage} ({$errorCode}).");
        }
        \stream_set_blocking($stream, false);
        try {
            $this->awaitStream($stream, false, $deadline);
            if (@\stream_socket_get_name($stream, true) === false) {
                throw new RedisConnectionException("Unable to connect to Redis at [{$uri}].");
            }
        } catch (Throwable $exception) {
            @\fclose($stream);
            throw $exception;
        }

        $this->stream = $stream;
        $this->pid = $pid;
        try {
            if ($this->configuration->password !== null) {
                $arguments = $this->configuration->username === null
                    ? [$this->configuration->password]
                    : [$this->configuration->username, $this->configuration->password];
                if ($this->raw('AUTH', $arguments, $deadline) !== 'OK') {
                    throw new RedisConnectionException('Redis authentication failed.');
                }
            }
            if ($this->configuration->database !== 0
                && $this->raw('SELECT', [$this->configuration->database], $deadline) !== 'OK'
            ) {
                throw new RedisConnectionException("Unable to select Redis database {$this->configuration->database}.");
            }
        } catch (RedisCommandException $exception) {
            $this->close();
            throw new RedisConnectionException('Redis authentication or database selection failed.', 0, $exception);
        }
    }

    /** @param list<string|int|float> $arguments */
    private function raw(string $command, array $arguments, float $deadline): mixed
    {
        $stream = $this->stream;
        if (!\is_resource($stream)) {
            throw new RedisConnectionException('Redis connection is not open.');
        }
        $payload = $this->encode([$command, ...$arguments]);
        $length = \strlen($payload);
        $offset = 0;
        while ($offset < $length) {
            $this->ensureBefore($deadline);
            $written = @\fwrite($stream, \substr($payload, $offset));
            if ($written === false) {
                throw new RedisConnectionException('Failed writing to Redis.');
            }
            if ($written === 0) {
                $this->awaitStream($stream, false, $deadline);
                continue;
            }
            $offset += $written;
        }

        while (true) {
            $this->ensureBefore($deadline);
            [$complete, $value, $consumed] = RespParser::parse(
                $this->readBuffer,
                $this->configuration->maxBulkLength,
                $this->configuration->maxArrayLength,
                $this->configuration->maxDepth,
            );
            if ($complete) {
                $this->readBuffer = \substr($this->readBuffer, $consumed);
                if ($value instanceof RedisCommandException) {
                    throw $value;
                }
                return $value;
            }
            $this->awaitStream($stream, true, $deadline);
            $data = @\fread($stream, 65_536);
            if ($data === false || ($data === '' && \feof($stream))) {
                throw new RedisConnectionException('Redis closed the connection.');
            }
            $this->readBuffer .= $data;
            if (\strlen($this->readBuffer) > $this->configuration->maxResponseSize) {
                throw new RedisProtocolException('Redis response exceeds the configured size limit.');
            }
        }
    }

    /** @param non-empty-list<string|int|float> $arguments */
    private function encode(array $arguments): string
    {
        $payload = '*' . \count($arguments) . "\r\n";
        foreach ($arguments as $argument) {
            $value = (string) $argument;
            $payload .= '$' . \strlen($value) . "\r\n{$value}\r\n";
        }
        return $payload;
    }

    /** @param resource $stream */
    private function awaitStream($stream, bool $readable, float $deadline): void
    {
        $remaining = $deadline - \microtime(true);
        if ($remaining <= 0) {
            throw new RedisTimeoutException('Redis command timed out.');
        }
        $deferred = new DeferredFuture();
        $future = $deferred->getFuture();
        $loop = EventLoop::get();
        $watcher = $readable
            ? $loop->onReadable($stream, static function () use ($deferred, $future): void {
                if (!$future->isComplete()) {
                    $deferred->complete();
                }
            })
            : $loop->onWritable($stream, static function () use ($deferred, $future): void {
                if (!$future->isComplete()) {
                    $deferred->complete();
                }
            });
        $timer = $loop->delay($remaining, static function () use ($deferred, $future): void {
            if (!$future->isComplete()) {
                $deferred->error(new RedisTimeoutException('Redis command timed out.'));
            }
        });
        try {
            $future->await();
        } finally {
            $loop->cancel($watcher);
            $loop->cancel($timer);
        }
    }

    private function ensureBefore(float $deadline): void
    {
        if (\microtime(true) >= $deadline) {
            throw new RedisTimeoutException('Redis command timed out.');
        }
    }

    /** @return array<string, array<string, bool|string>> */
    private function streamOptions(): array
    {
        if ($this->configuration->scheme !== 'tls') {
            return [];
        }
        $options = [
            'verify_peer' => $this->configuration->verifyPeer,
            'verify_peer_name' => $this->configuration->verifyPeer,
            'allow_self_signed' => !$this->configuration->verifyPeer,
        ];
        if ($this->configuration->peerName !== null) {
            $options['peer_name'] = $this->configuration->peerName;
        }
        return ['ssl' => $options];
    }
}
