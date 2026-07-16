<?php

declare(strict_types=1);

/**
 * Persistência das blueprints do Canvas e seus cursos.
 *
 * Cada documento da collection representa uma blueprint com os cursos embutidos:
 *   {
 *     blueprint_id: "130764",
 *     base_url: "https://...",
 *     created_at, updated_at,
 *     courses: [
 *       { course_id: 136272, name, course_code, sis_course_id, term_name,
 *         status: "Ativa", collected_at }
 *     ]
 *   }
 */
final class BlueprintRepository
{
    private const STATUS_NEW = 'Ativa';

    public function __construct(private readonly MongoConnection $connection)
    {
    }

    /**
     * Grava (registra ou atualiza) uma blueprint com os cursos buscados no Canvas,
     * preservando o status dos cursos já existentes e adicionando os novos como "Ativa".
     *
     * @param list<array<string, mixed>> $fetchedCourses
     * @return array{added:int, total:int, previous:int}
     */
    public function saveBlueprintCourses(string $blueprintId, string $baseUrl, array $fetchedCourses): array
    {
        $now = new MongoDB\BSON\UTCDateTime((int) (microtime(true) * 1000));
        $existing = $this->findCourses($blueprintId);
        $merged = self::mergeCourses($existing, $fetchedCourses, $now);

        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->update(
            ['blueprint_id' => $blueprintId],
            [
                '$set' => [
                    'blueprint_id' => $blueprintId,
                    'base_url' => $baseUrl,
                    'courses' => $merged['courses'],
                    'updated_at' => $now,
                ],
                '$setOnInsert' => ['created_at' => $now],
            ],
            ['upsert' => true]
        );

        $this->connection->manager()->executeBulkWrite($this->connection->namespace(), $bulk);

        return [
            'added' => $merged['added'],
            'total' => count($merged['courses']),
            'previous' => count($existing),
        ];
    }

    /**
     * Combina os cursos existentes com os recém-buscados: mantém o status dos que
     * já existem (atualizando os dados descritivos e a data de coleta), adiciona os
     * novos como "Ativa" e preserva os que deixaram de ser retornados.
     *
     * @param list<array<string, mixed>> $existing
     * @param list<array<string, mixed>> $fetched
     * @return array{courses:list<array<string, mixed>>, added:int}
     */
    public static function mergeCourses(array $existing, array $fetched, MongoDB\BSON\UTCDateTime $now): array
    {
        $byId = [];

        foreach ($existing as $course) {
            $byId[(int) ($course['course_id'] ?? 0)] = $course;
        }

        $result = [];
        $added = 0;

        foreach ($fetched as $course) {
            $id = (int) ($course['course_id'] ?? 0);

            if ($id === 0) {
                continue;
            }

            if (isset($byId[$id])) {
                $merged = $byId[$id];
                $merged['name'] = (string) ($course['name'] ?? '');
                $merged['course_code'] = (string) ($course['course_code'] ?? '');
                $merged['sis_course_id'] = (string) ($course['sis_course_id'] ?? '');
                $merged['term_name'] = (string) ($course['term_name'] ?? '');
                $merged['collected_at'] = $now;
                $result[$id] = $merged;
                unset($byId[$id]);
            } else {
                $result[$id] = [
                    'course_id' => $id,
                    'name' => (string) ($course['name'] ?? ''),
                    'course_code' => (string) ($course['course_code'] ?? ''),
                    'sis_course_id' => (string) ($course['sis_course_id'] ?? ''),
                    'term_name' => (string) ($course['term_name'] ?? ''),
                    'status' => self::STATUS_NEW,
                    'collected_at' => $now,
                ];
                $added++;
            }
        }

        // Preserva cursos que já estavam salvos mas não vieram nesta coleta.
        foreach ($byId as $leftover) {
            $result[(int) ($leftover['course_id'] ?? 0)] = $leftover;
        }

        return ['courses' => array_values($result), 'added' => $added];
    }

    /**
     * Indica se um curso corresponde ao filtro de busca (por ID ou por nome).
     *
     * @param array<string, mixed> $course
     */
    public static function courseMatches(array $course, string $filter): bool
    {
        $filter = trim($filter);

        if ($filter === '') {
            return true;
        }

        $needle = mb_strtolower($filter);
        $name = mb_strtolower((string) ($course['name'] ?? ''));

        if (str_contains($name, $needle)) {
            return true;
        }

        if (str_contains((string) ($course['course_id'] ?? ''), $filter)) {
            return true;
        }

        return str_contains((string) ($course['sis_course_id'] ?? ''), $filter);
    }

    /**
     * Lista as blueprints paginadas, aplicando (opcionalmente) o filtro por ID/nome
     * de curso. Blueprints são a unidade de paginação; dentro de cada uma, apenas os
     * cursos que correspondem ao filtro são retornados.
     *
     * @return array{items:list<array<string, mixed>>, total:int, page:int, per_page:int}
     */
    public function paginate(string $filter, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $filter = trim($filter);
        $query = $this->buildFilter($filter);
        $skip = ($page - 1) * $perPage;

        $mongoQuery = new MongoDB\Driver\Query($query, [
            'sort' => ['updated_at' => -1, 'blueprint_id' => 1],
            'skip' => $skip,
            'limit' => $perPage,
        ]);

        $cursor = $this->connection->manager()->executeQuery($this->connection->namespace(), $mongoQuery);
        $items = [];

        foreach ($cursor as $document) {
            $items[] = $this->normalizeBlueprint($document, $filter);
        }

        return [
            'items' => $items,
            'total' => $this->count($query),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Atualiza o status dos cursos selecionados de uma blueprint. Se $courseIds for
     * vazio, atualiza todos os cursos da blueprint.
     *
     * @param list<int> $courseIds
     */
    public function updateCoursesStatus(string $blueprintId, array $courseIds, string $status): int
    {
        $now = new MongoDB\BSON\UTCDateTime((int) (microtime(true) * 1000));
        $bulk = new MongoDB\Driver\BulkWrite();

        if ($courseIds === []) {
            $bulk->update(
                ['blueprint_id' => $blueprintId],
                ['$set' => ['courses.$[].status' => $status, 'updated_at' => $now]]
            );
        } else {
            $courseIds = array_values(array_unique(array_map('intval', $courseIds)));
            $bulk->update(
                ['blueprint_id' => $blueprintId],
                ['$set' => ['courses.$[course].status' => $status, 'updated_at' => $now]],
                ['arrayFilters' => [['course.course_id' => ['$in' => $courseIds]]]]
            );
        }

        $result = $this->connection->manager()->executeBulkWrite($this->connection->namespace(), $bulk);

        return $result->getModifiedCount();
    }

    public function blueprintExists(string $blueprintId): bool
    {
        $query = new MongoDB\Driver\Query(['blueprint_id' => $blueprintId], ['limit' => 1, 'projection' => ['_id' => 1]]);
        $cursor = $this->connection->manager()->executeQuery($this->connection->namespace(), $query);

        return current($cursor->toArray()) !== false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findCourses(string $blueprintId): array
    {
        $query = new MongoDB\Driver\Query(['blueprint_id' => $blueprintId], ['limit' => 1]);
        $cursor = $this->connection->manager()->executeQuery($this->connection->namespace(), $query);
        $document = current($cursor->toArray());

        if (!is_object($document) || !isset($document->courses)) {
            return [];
        }

        $courses = [];

        foreach ((array) $document->courses as $course) {
            $course = (array) $course;
            $courses[] = [
                'course_id' => (int) ($course['course_id'] ?? 0),
                'name' => (string) ($course['name'] ?? ''),
                'course_code' => (string) ($course['course_code'] ?? ''),
                'sis_course_id' => (string) ($course['sis_course_id'] ?? ''),
                'term_name' => (string) ($course['term_name'] ?? ''),
                'status' => (string) ($course['status'] ?? self::STATUS_NEW),
                'collected_at' => $course['collected_at'] ?? null,
            ];
        }

        return $courses;
    }

    private function buildFilter(string $filter): array
    {
        if ($filter === '') {
            return [];
        }

        $regex = new MongoDB\BSON\Regex(preg_quote($filter, ''), 'i');
        $clauses = [
            ['courses' => ['$elemMatch' => ['name' => $regex]]],
            ['courses' => ['$elemMatch' => ['sis_course_id' => $regex]]],
        ];

        if (ctype_digit($filter)) {
            $clauses[] = ['courses' => ['$elemMatch' => ['course_id' => (int) $filter]]];
        }

        return ['$or' => $clauses];
    }

    private function count(array $query): int
    {
        $command = new MongoDB\Driver\Command([
            'count' => $this->connection->collection(),
            'query' => (object) $query,
        ]);

        $cursor = $this->connection->manager()->executeCommand($this->connection->database(), $command);
        $result = current($cursor->toArray());

        return is_object($result) && isset($result->n) ? (int) $result->n : 0;
    }

    private function normalizeBlueprint(object $document, string $filter): array
    {
        $courses = [];

        foreach ((array) ($document->courses ?? []) as $course) {
            $course = (array) $course;
            $normalized = [
                'course_id' => (int) ($course['course_id'] ?? 0),
                'name' => (string) ($course['name'] ?? ''),
                'course_code' => (string) ($course['course_code'] ?? ''),
                'sis_course_id' => (string) ($course['sis_course_id'] ?? ''),
                'term_name' => (string) ($course['term_name'] ?? ''),
                'status' => (string) ($course['status'] ?? self::STATUS_NEW),
                'collected_at' => $this->stringifyDate($course['collected_at'] ?? null),
            ];

            if (self::courseMatches($normalized, $filter)) {
                $courses[] = $normalized;
            }
        }

        return [
            'blueprint_id' => (string) ($document->blueprint_id ?? ''),
            'updated_at' => $this->stringifyDate($document->updated_at ?? null),
            'course_count' => count($courses),
            'courses' => $courses,
        ];
    }

    private function stringifyDate(mixed $value): string
    {
        if ($value instanceof MongoDB\BSON\UTCDateTime) {
            return $value->toDateTime()->format('d/m/Y H:i');
        }

        return '';
    }
}
