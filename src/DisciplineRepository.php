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
            // Mais recentemente alterada/criada primeiro (campo `data`); código como desempate.
            'sort' => [
                'data' => -1,
                'codigo_disciplina' => 1,
            ],
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
        $objectIds = $this->toObjectIds($ids);

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

    /**
     * Retorna os códigos de disciplina (codigo_disciplina) dos documentos
     * correspondentes aos _ids informados.
     */
    public function findCodesByIds(array $ids): array
    {
        $objectIds = $this->toObjectIds($ids);

        if ($objectIds === []) {
            return [];
        }

        $query = new MongoDB\Driver\Query(['_id' => ['$in' => $objectIds]]);
        $cursor = $this->connection->manager()->executeQuery($this->connection->namespace(), $query);
        $codes = [];

        foreach ($cursor as $document) {
            $code = $this->firstValue($document, ['codigo_disciplina', 'Codigo da Disciplina', 'Código da Disciplina']);

            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * Dentre os códigos informados, retorna os que JÁ existem na collection
     * (considerando também os nomes de campo legados).
     *
     * @param list<string> $codes
     * @return list<string>
     */
    public function existingCodes(array $codes): array
    {
        $codes = array_values(array_unique(array_filter(
            array_map(static fn ($code): string => trim((string) $code), $codes),
            static fn (string $code): bool => $code !== ''
        )));

        if ($codes === []) {
            return [];
        }

        $query = new MongoDB\Driver\Query([
            '$or' => [
                ['codigo_disciplina' => ['$in' => $codes]],
                ['Codigo da Disciplina' => ['$in' => $codes]],
                ['Código da Disciplina' => ['$in' => $codes]],
            ],
        ], [
            'projection' => ['codigo_disciplina' => 1, 'Codigo da Disciplina' => 1, 'Código da Disciplina' => 1],
        ]);

        $cursor = $this->connection->manager()->executeQuery($this->connection->namespace(), $query);
        $found = [];

        foreach ($cursor as $document) {
            $code = $this->firstValue($document, ['codigo_disciplina', 'Codigo da Disciplina', 'Código da Disciplina']);

            if ($code !== '') {
                $found[$code] = true;
            }
        }

        return array_keys($found);
    }

    private function toObjectIds(array $ids): array
    {
        $objectIds = [];

        foreach ($ids as $id) {
            try {
                $objectIds[] = new MongoDB\BSON\ObjectId((string) $id);
            } catch (Throwable) {
                continue;
            }
        }

        return $objectIds;
    }

    /**
     * Insere apenas as disciplinas cujo codigo_disciplina ainda não existe,
     * com status "Ativa". Disciplinas já cadastradas não são alteradas.
     * Usa $setOnInsert com upsert, então é idempotente e seguro para
     * reenvios do mesmo arquivo.
     *
     * @param list<array<string, mixed>> $activities
     * @return list<string> Códigos efetivamente inseridos (disciplinas novas)
     */
    public function insertNewActivities(array $activities): array
    {
        $bulk = new MongoDB\Driver\BulkWrite();
        $codesByIndex = [];
        $index = 0;

        foreach ($activities as $activity) {
            $codigo = trim((string) ($activity['codigo_disciplina'] ?? ''));

            if ($codigo === '') {
                continue;
            }

            $bulk->update(
                ['$or' => [
                    ['codigo_disciplina' => $codigo],
                    ['Codigo da Disciplina' => $codigo],
                    ['Código da Disciplina' => $codigo],
                ]],
                ['$setOnInsert' => [
                    'codigo_disciplina' => $codigo,
                    'nome_disciplina' => (string) ($activity['nome_disciplina'] ?? ''),
                    'bloco' => (string) ($activity['bloco'] ?? ''),
                    'ano' => $activity['ano'] ?? '',
                    'status' => 'Ativa',
                    'data' => new MongoDB\BSON\UTCDateTime((int) (microtime(true) * 1000)),
                ]],
                ['upsert' => true]
            );
            $codesByIndex[$index] = $codigo;
            $index++;
        }

        if ($index === 0) {
            return [];
        }

        $result = $this->connection->manager()->executeBulkWrite($this->connection->namespace(), $bulk);

        // getUpsertedIds() devolve [índiceDaOperação => _id] apenas das que foram
        // realmente inseridas — mapeamos de volta para os códigos.
        $newCodes = [];

        foreach (array_keys($result->getUpsertedIds()) as $operationIndex) {
            if (isset($codesByIndex[$operationIndex])) {
                $newCodes[] = $codesByIndex[$operationIndex];
            }
        }

        return $newCodes;
    }

    /**
     * Insere apenas os códigos ainda inexistentes gravando SOMENTE
     * codigo_disciplina + status + data (sem nome, bloco ou ano). Disciplinas
     * já cadastradas não são alteradas.
     *
     * @param list<string> $codes
     * @return list<string> Códigos efetivamente inseridos
     */
    public function insertNewCodes(array $codes, string $status): array
    {
        $bulk = new MongoDB\Driver\BulkWrite();
        $codesByIndex = [];
        $index = 0;

        foreach ($codes as $code) {
            $code = trim((string) $code);

            if ($code === '') {
                continue;
            }

            $bulk->update(
                ['$or' => [
                    ['codigo_disciplina' => $code],
                    ['Codigo da Disciplina' => $code],
                    ['Código da Disciplina' => $code],
                ]],
                ['$setOnInsert' => [
                    'codigo_disciplina' => $code,
                    'status' => $status,
                    'data' => new MongoDB\BSON\UTCDateTime((int) (microtime(true) * 1000)),
                ]],
                ['upsert' => true]
            );
            $codesByIndex[$index] = $code;
            $index++;
        }

        if ($index === 0) {
            return [];
        }

        $result = $this->connection->manager()->executeBulkWrite($this->connection->namespace(), $bulk);

        $newCodes = [];

        foreach (array_keys($result->getUpsertedIds()) as $operationIndex) {
            if (isset($codesByIndex[$operationIndex])) {
                $newCodes[] = $codesByIndex[$operationIndex];
            }
        }

        return $newCodes;
    }

    /**
     * Cadastra UMA disciplina. Apenas o código é obrigatório; nome, bloco e ano
     * só são gravados quando informados. Não altera uma disciplina já existente.
     *
     * @return bool true se inseriu; false se o código já existia
     */
    public function insertSingleActivity(
        string $code,
        string $status,
        string $name = '',
        string $block = '',
        string $year = ''
    ): bool {
        $code = trim($code);

        if ($code === '') {
            return false;
        }

        $document = [
            'codigo_disciplina' => $code,
            'status' => $status,
            'data' => new MongoDB\BSON\UTCDateTime((int) (microtime(true) * 1000)),
        ];

        if (trim($name) !== '') {
            $document['nome_disciplina'] = trim($name);
        }

        if (trim($block) !== '') {
            $document['bloco'] = trim($block);
        }

        $year = trim($year);

        if ($year !== '') {
            $document['ano'] = ctype_digit($year) ? (int) $year : $year;
        }

        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->update(
            ['$or' => [
                ['codigo_disciplina' => $code],
                ['Codigo da Disciplina' => $code],
                ['Código da Disciplina' => $code],
            ]],
            ['$setOnInsert' => $document],
            ['upsert' => true]
        );

        $result = $this->connection->manager()->executeBulkWrite($this->connection->namespace(), $bulk);

        return $result->getUpsertedCount() > 0;
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
