<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/MongoConnection.php';

$config = require __DIR__ . '/../config/config.php';

$collections = [
    $config['mongo']['activity_collection'],
    $config['mongo']['user_collection'],
];

foreach ($collections as $collection) {
    $connection = new MongoConnection(
        $config['mongo']['uri'],
        $config['mongo']['database'],
        $collection
    );

    $command = new MongoDB\Driver\Command([
        'count' => $collection,
        'query' => (object) [],
    ]);

    $cursor = $connection->manager()->executeCommand($connection->database(), $command);
    $result = current($cursor->toArray());
    $count = is_object($result) && isset($result->n) ? (int) $result->n : 0;

    echo $collection . ': ' . $count . PHP_EOL;
}
