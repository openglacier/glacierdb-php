<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use GlacierDB\Client;

$client = new Client(
    identity: 'data/bootstrap/admin.ogid',
);

$client->connect();

$result = $client->query('on data | limit 10');

foreach ($result->documents as $document) {
    print_r($document);
}

print_r($result->statistics);
