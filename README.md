# glacierdb PHP Driver

PHP 8.2+ client for glacierdb.

## Install

```bash
composer require glacierdb/glacierdb
```

For a local checkout:

```bash
composer install
```

Requires PHP's Sodium extension.

## Authenticated query

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use GlacierDB\Client;

$client = new Client(
    host: '127.0.0.1',
    port: 7878,
    identity: 'data/bootstrap/admin.ogid',
);

$client->connect();

$result = $client->query('on data | limit 10');

foreach ($result->documents as $document) {
    var_dump($document);
}
```

## Streaming query

Avoid collecting large query results:

```php
$client->execute(
    'on data',
    function ($document): void {
        // Process each partial response immediately.
        var_dump($document);
    },
);
```

## CSV loading

```php
$count = $client->loadCsv(
    collection: 'customers',
    path: 'customers.csv',
    batchSize: 1000,
);

echo "Loaded {$count} rows\n";
```

## Protocol

The driver implements the current Core protocol:

- TCP `uint32` big-endian payload length
- MessagePack payload
- `auth.begin` / Ed25519 challenge / `auth.complete`
- `query.execute`
- `partial`, `complete`, `error` stream responses
- multiplexed events such as `core.heartbeat`
