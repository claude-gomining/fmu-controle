<?php

declare(strict_types=1);

/**
 * Testes do CanvasClient (contra um Canvas simulado, com paginação via Link)
 * e da lógica pura do BlueprintRepository (merge de cursos e filtro).
 * Não exigem MongoDB para o merge/filtro; o CanvasClient usa um servidor local.
 * Execução: php tests/canvas-test.php
 */

require_once __DIR__ . '/../src/CanvasException.php';
require_once __DIR__ . '/../src/CanvasClient.php';
require_once __DIR__ . '/../src/MongoConnection.php';
require_once __DIR__ . '/../src/BlueprintRepository.php';

// Stub mínimo para testar a lógica pura de merge sem a extensão mongodb.
if (!class_exists('MongoDB\BSON\UTCDateTime')) {
    eval('namespace MongoDB\\BSON; class UTCDateTime { public function __construct(...$args) {} }');
}

$failures = 0;
$assertions = 0;

function check(bool $condition, string $description): void
{
    global $failures, $assertions;
    $assertions++;
    echo ($condition ? "  ok - " : "  FALHOU - ") . $description . "\n";
    if (!$condition) {
        $failures++;
    }
}

$port = 8198;
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/mock-canvas-server.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);

if (!is_resource($server)) {
    fwrite(STDERR, "Não foi possível iniciar o servidor de teste.\n");
    exit(1);
}

$up = false;
for ($i = 0; $i < 50; $i++) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($socket !== false) {
        fclose($socket);
        $up = true;
        break;
    }
    usleep(100_000);
}

if (!$up) {
    fwrite(STDERR, "Servidor de teste não respondeu.\n");
    proc_terminate($server);
    exit(1);
}

$base = "http://127.0.0.1:{$port}";

echo "CanvasClient: paginação via Link\n";
$client = new CanvasClient($base, 'token-ok', 5, 100, 10);
$courses = $client->fetchAssociatedCourses('130764');
check(count($courses) === 3, 'segue a paginação e agrega as duas páginas (3 cursos)');
check($courses[0]['course_id'] === 136272 && is_int($courses[0]['course_id']), 'id vira inteiro em course_id');
check($courses[0]['name'] === 'DIREITO CIVIL (Turma 1)', 'nome sofre trim');
check($courses[0]['sis_course_id'] === '194554', 'sis_course_id preservado');
check($courses[2]['course_id'] === 150999, 'curso da segunda página presente');

echo "CanvasClient: erros\n";
$ok = false;
try {
    (new CanvasClient($base, 'FAIL', 5))->fetchAssociatedCourses('130764');
} catch (CanvasException $e) {
    $ok = str_contains($e->getMessage(), 'inválido') || str_contains($e->getMessage(), 'permissão');
}
check($ok, 'token inválido (401) lança CanvasException');

$ok = false;
try {
    (new CanvasClient($base, 'token-ok', 5))->fetchAssociatedCourses('999999');
} catch (CanvasException $e) {
    $ok = str_contains($e->getMessage(), 'não encontrada');
}
check($ok, 'blueprint inexistente (404) lança CanvasException');

$ok = false;
try {
    (new CanvasClient($base, 'token-ok', 5))->fetchAssociatedCourses('abc');
} catch (CanvasException $e) {
    $ok = str_contains($e->getMessage(), 'inválido');
}
check($ok, 'código não numérico é rejeitado');

$ok = false;
try {
    (new CanvasClient($base, '', 5))->fetchAssociatedCourses('130764');
} catch (CanvasException $e) {
    $ok = str_contains($e->getMessage(), 'Token');
}
check($ok, 'token ausente é rejeitado antes de qualquer chamada');

proc_terminate($server);
proc_close($server);

echo "BlueprintRepository::mergeCourses\n";
$now = new MongoDB\BSON\UTCDateTime(1_700_000_000_000);
$existing = [
    ['course_id' => 100, 'name' => 'Antigo', 'course_code' => 'A', 'sis_course_id' => '1', 'term_name' => 'T', 'status' => 'Inativa', 'collected_at' => null],
    ['course_id' => 200, 'name' => 'Removido', 'course_code' => 'B', 'sis_course_id' => '2', 'term_name' => 'T', 'status' => 'Ativa', 'collected_at' => null],
];
$fetched = [
    ['course_id' => 100, 'name' => 'Atualizado', 'course_code' => 'A2', 'sis_course_id' => '1', 'term_name' => 'T2'],
    ['course_id' => 300, 'name' => 'Novo', 'course_code' => 'C', 'sis_course_id' => '3', 'term_name' => 'T'],
];
$merged = BlueprintRepository::mergeCourses($existing, $fetched, $now);
$byId = [];
foreach ($merged['courses'] as $c) {
    $byId[$c['course_id']] = $c;
}
echo "BlueprintRepository::summarizeCourses (API)\n";
$sample = [
    ['course_id' => 1, 'status' => 'Ativa'],
    ['course_id' => 2, 'status' => 'Inativa'],
    ['course_id' => 3, 'status' => 'Ativa'],
    ['course_id' => 4],  // sem status: conta como Ativa (padrão)
];
$all = BlueprintRepository::summarizeCourses($sample, null);
check(count($all['courses']) === 4, 'sem filtro devolve todos os cursos');
check($all['active_count'] === 3 && $all['inactive_count'] === 1, 'contagem de ativos/inativos correta (sem status = Ativa)');

$onlyActive = BlueprintRepository::summarizeCourses($sample, 'Ativa');
check(array_column($onlyActive['courses'], 'course_id') === [1, 3, 4], 'filtro Ativa devolve só os ativos');
check($onlyActive['active_count'] === 3 && $onlyActive['inactive_count'] === 1, 'contadores refletem o TOTAL, não o filtro');

$onlyInactive = BlueprintRepository::summarizeCourses($sample, 'Inativa');
check(array_column($onlyInactive['courses'], 'course_id') === [2], 'filtro Inativa devolve só os inativos');
check(BlueprintRepository::summarizeCourses([], 'Ativa')['courses'] === [], 'blueprint sem cursos devolve lista vazia');

check($merged['added'] === 1, 'apenas 1 curso novo contabilizado');
check($merged['added_ids'] === [300], 'added_ids contém apenas o course_id do curso novo');
check(count($merged['courses']) === 3, 'mantém existente atualizado + novo + removido preservado');
check($byId[100]['status'] === 'Inativa', 'status do curso existente é preservado no merge');
check($byId[100]['name'] === 'Atualizado', 'dados descritivos do curso existente são atualizados');
check($byId[300]['status'] === 'Ativa', 'curso novo entra como Ativa');
check(isset($byId[200]) && $byId[200]['name'] === 'Removido', 'curso que não veio na coleta é preservado');
check($byId[300]['collected_at'] === $now, 'collected_at do novo curso é a data da coleta');

echo "BlueprintRepository::courseMatches\n";
$course = ['course_id' => 136272, 'name' => 'DIREITO CIVIL', 'sis_course_id' => '194554'];
check(BlueprintRepository::courseMatches($course, '') === true, 'filtro vazio casa com tudo');
check(BlueprintRepository::courseMatches($course, 'direito') === true, 'casa por nome (case-insensitive)');
check(BlueprintRepository::courseMatches($course, '136272') === true, 'casa por ID do curso');
check(BlueprintRepository::courseMatches($course, '3627') === true, 'casa por parte do ID');
check(BlueprintRepository::courseMatches($course, '194554') === true, 'casa por SIS course id');
check(BlueprintRepository::courseMatches($course, 'penal') === false, 'não casa quando nada corresponde');

echo "\n{$assertions} asserções, {$failures} falha(s)\n";
exit($failures === 0 ? 0 : 1);
