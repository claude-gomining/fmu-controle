<?php

declare(strict_types=1);

/**
 * Simula o endpoint do Canvas de cursos associados a uma blueprint, com
 * paginação via header Link (rel="next"). Usado por tests/canvas-test.php.
 *
 * - Exige header Authorization; "Bearer FAIL" devolve 401.
 * - Página 1: 2 cursos + Link next -> page=2. Página 2: 1 curso, sem next.
 * - Blueprint 999999 devolve 404.
 */

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (stripos($auth, 'Bearer ') !== 0 || trim(substr($auth, 7)) === '') {
    http_response_code(401);
    header('Content-Type: application/json');
    echo '{"errors":[{"message":"unauthorized"}]}';
    exit;
}

if (trim(substr($auth, 7)) === 'FAIL') {
    http_response_code(401);
    header('Content-Type: application/json');
    echo '{"errors":[{"message":"invalid token"}]}';
    exit;
}

$uri = $_SERVER['REQUEST_URI'] ?? '';

if (str_contains($uri, '/courses/999999/')) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"errors":[{"message":"not found"}]}';
    exit;
}

$host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
$page = (int) ($query['page'] ?? 1);

header('Content-Type: application/json');

if ($page <= 1) {
    $base = 'http://' . $host . parse_url($uri, PHP_URL_PATH);
    header('Link: <' . $base . '?per_page=100&page=2>; rel="next", <' . $base . '?per_page=100&page=2>; rel="last"');
    echo json_encode([
        [
            'id' => 136272,
            'name' => '  DIREITO CIVIL (Turma 1)  ',
            'course_code' => 'Turma 1 - DIREITO CIVIL',
            'sis_course_id' => '194554',
            'term_name' => 'Semestre 2025/2',
        ],
        [
            'id' => 140322,
            'name' => 'DIREITO PENAL (Turma 2)',
            'course_code' => 'Turma 2 - DIREITO PENAL',
            'sis_course_id' => '198797',
            'term_name' => 'Semestre 2025/2',
        ],
    ]);
    exit;
}

echo json_encode([
    [
        'id' => 150999,
        'name' => 'DIREITO CONSTITUCIONAL (Turma 3)',
        'course_code' => 'Turma 3 - DIREITO CONSTITUCIONAL',
        'sis_course_id' => '201111',
        'term_name' => 'Semestre 2025/2',
    ],
]);
