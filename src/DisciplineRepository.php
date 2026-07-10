<?php

declare(strict_types=1);

final class DisciplineRepository
{
    private const SEARCH_FIELDS = [
        'nome_disciplina',
        'Nome da disciplina',
        'Nome da Disciplina',
        'codigo_disciplina',
        'Codigo da Disciplina',
        'Código da Disciplina',
    ];

    public function __construct(private readonly MongoConnection $connection)
    {
    }

    public function paginate(array $criteria, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $filter = $this->buildFilter($criteria);
        $skip = ($page - 1) * $perPage;

        $query = new MongoDB\Driver\Query($filter, [
            'sort' => ['nome_disciplina' => 1, 'Nome da disciplina' => 1],
            'skip' => $skip,
            'limit' => $perPage,
        ]);

        $cursor = $this->connection->manager()->executeQuery($this->connection->namespace(), $query);
        $items = [];

        foreach ($cursor as $document) {
            $items[] = $this->normalizeDocument($document);
        }

        return [
            'items' => $items,
            'total' => $this->count($filter),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function updateStatus(array $ids, string $status): int
    {
        $objectIds = [];

        foreach ($ids as $id) {
            try {
                $objectIds[] = new MongoDB\BSON\ObjectId((string) $id);
            } catch (Throwable) {
                continue;
            }
        }

        if ($objectIds === []) {
            return 0;
        }

        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->update(
            ['_id' => ['$in' => $objectIds]],
            ['$set' => [
                'status' => $status,
                'data' => new MongoDB\BSON\UTCDateTime((int) (microtime(true) * 1000)),
            ]],
            ['multi' => true]
        );

        $result = $this->connection->manager()->executeBulkWrite($this->connection->namespace(), $bulk);

        return $result->getModifiedCount();
    }

    public function distinctBlocks(): array
    {
        $values = [];

        foreach (['bloco', 'Bloco'] as $field) {
            $command = new MongoDB\Driver\Command([
                'distinct' => $this->connection->collection(),
                'key' => $field,
            ]);

            $cursor = $this->connection->manager()->executeCommand($this->connection->database(), $command);
            $result = current($cursor->toArray());

            if (is_object($result) && isset($result->values) && is_array($result->values)) {
                $values = array_merge($values, $result->values);
            }
        }

        $values = array_values(array_unique(array_filter(array_map('strval', $values), static fn (string $value) => $value !== '')));
        sort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return $values;
    }

    private function count(array $filter): int
    {
        $command = new MongoDB\Driver\Command([
            'count' => $this->connection->collection(),
            'query' => (object) $filter,
        ]);

        $cursor = $this->connection->manager()->executeCommand($this->connection->database(), $command);
        $result = current($cursor->toArray());

        return is_object($result) && isset($result->n) ? (int) $result->n : 0;
    }

    private function buildFilter(array $criteria): array
    {
        $clauses = [];
        $search = trim((string) ($criteria['search'] ?? ''));
        $block = trim((string) ($criteria['block'] ?? ''));
        $status = trim((string) ($criteria['status'] ?? ''));

        if ($search !== '') {
            $regex = new MongoDB\BSON\Regex(preg_quote($search), 'i');
            $or = [];

            foreach (self::SEARCH_FIELDS as $field) {
                $or[] = [$field => $regex];
            }

            $clauses[] = ['$or' => $or];
        }

        if ($block !== '') {
            $clauses[] = ['$or' => [
                ['bloco' => $block],
                ['Bloco' => $block],
            ]];
        }

        if ($status !== '') {
            $clauses[] = ['$or' => [
                ['status' => $status],
                ['Status' => $status],
            ]];
        }

        if ($clauses === []) {
            return [];
        }

        if (count($clauses) === 1) {
            return $clauses[0];
        }

        return ['$and' => $clauses];
    }

    private function normalizeDocument(object $document): array
    {
        return [
            'id' => isset($document->_id) ? (string) $document->_id : '',
            'nome_disciplina' => $this->firstValue($document, ['nome_disciplina', 'Nome da disciplina', 'Nome da Disciplina']),
            'bloco' => $this->firstValue($document, ['bloco', 'Bloco']),
            'ano' => $this->firstValue($document, ['ano', 'Ano']),
            'codigo_disciplina' => $this->firstValue($document, ['codigo_disciplina', 'Codigo da Disciplina', 'Código da Disciplina']),
            'status' => $this->firstValue($document, ['status', 'Status']),
        ];
    }

    private function firstValue(object $document, array $fields): string
    {
        foreach ($fields as $field) {
            if (isset($document->{$field})) {
                return $this->stringify($document->{$field});
            }
        }

        return '';
    }

    private function stringify(mixed $value): string
    {
        if ($value instanceof MongoDB\BSON\UTCDateTime) {
            return $value->toDateTime()->format('d/m/Y H:i');
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
