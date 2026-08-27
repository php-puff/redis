# Puff Redis

Non-blocking RESP2 Redis client with a Fiber-aware connection pool for Puff.

```php
$redis = redis();
$redis->set('name', 'Puff', 60);
echo $redis->get('name');
```

## Configuration

```php
'redis' => [
    'default' => [
        'scheme' => 'tcp', // tcp, tls, or unix
        'host' => '127.0.0.1',
        'port' => 6379,
        'username' => null,
        'password' => null,
        'database' => 0,
        'timeout' => 5.0,
        'pool' => 10,
        'verify_peer' => true,
        'peer_name' => null,
    ],
],
```

Environment overrides use double underscores, for example `REDIS__DEFAULT__HOST` and `REDIS__DEFAULT__PASSWORD`.

Connections are opened lazily. Each concurrent command borrows an exclusive connection, so responses cannot cross Fiber boundaries. The timeout is a total deadline covering pool acquisition, connect, authentication, writes, and reads. Connections inherited across `fork()` are detected by PID and reopened.

## Commands

```php
$redis->has('key');
$redis->get('key');
$redis->set('key', 'value', 60);
$redis->getMultiple(['one', 'two']);
$redis->setMultiple(['one' => 1, 'two' => 2]);
$redis->increment('counter');
$redis->delete('one', 'two');
```

The namespaced facade is available for concise application code:

```php
use Puff\Redis\Facades\Redis;

Redis::set('name', 'Puff', 60);
echo Redis::get('name');
```

Constructor injection remains preferable for reusable services and for operations that own a connection lifecycle. The facade is namespaced deliberately to avoid conflicting with the global `Redis` class provided by `ext-redis`.

Use `scan()` when the result may be large:

```php
foreach ($redis->scan('user:*', 500) as $key) {
    // Process one key without collecting the complete keyspace in memory.
}
```

## Exclusive connections and transactions

Connection-state and blocking commands are rejected by normal `execute()` calls. Use an exclusive connection when required:

```php
$result = $redis->withConnection(
    static fn (Puff\Redis\Connection $connection) => $connection->execute('BLPOP', 'queue', 5),
);
```

Transactions retain one connection for the complete callback:

```php
$result = $redis->transaction(static function (Puff\Redis\Connection $connection): void {
    $connection->execute('SET', 'one', 1);
    $connection->execute('SET', 'two', 2);
});
```

Pub/Sub requires a dedicated long-lived subscriber and is not exposed by this command client. RESP3, Redis Cluster, and Sentinel discovery are not currently implemented.

Protocol limits in `redis.php` bound response size, bulk length, array length, and nesting depth. TLS peer verification is enabled by default.
