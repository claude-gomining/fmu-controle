<?php

declare(strict_types=1);

final class MongoConnection
{
    private MongoDB\Driver\Manager $manager;

    public function __construct(
        private readonly string $uri,
        private readonly string $database,
        private readonly string $collection
    ) {
        if (!extension_loaded('mongodb')) {
            throw new RuntimeException('A extensao PHP mongodb nao esta habilitada.');
        }

        $this->manager = new MongoDB\Driver\Manager($this->uri);
    }

    public function manager(): MongoDB\Driver\Manager
    {
        return $this->manager;
    }

    public function database(): string
    {
        return $this->database;
    }

    public function collection(): string
    {
        return $this->collection;
    }

    public function namespace(): string
    {
        return $this->database . '.' . $this->collection;
    }
}
