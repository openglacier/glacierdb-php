<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use GlacierDB\Client;

$client = new Client(
    identity: 'data/bootstrap/admin.ogid',
);

$client->connect();

$count = $client->loadCsv(
    collection: 'customers',
    path: 'customers.csv',
    batchSize: 1000,
);

echo "Loaded {$count} rows\n";
