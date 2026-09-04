<?php

declare(strict_types=1);

/**
 * API de consulta (somente leitura) das blueprints e cursos da AFYA.
 *
 *   GET api-afya.php?status=ativa    -> apenas blueprints/cursos ATIVOS
 *   GET api-afya.php?status=inativa  -> apenas blueprints/cursos INATIVOS
 *   GET api-afya.php                 -> TODOS, com o status de cada um
 *
 * Autenticação (uma das duas):
 *   - sessão do portal já autenticada com acesso ao painel AFYA; ou
 *   - header `Authorization: Bearer <API_TOKEN>` (defina API_TOKEN no ambiente).
 *
 * Sem API_TOKEN configurado, só a sessão é aceita: o endpoint nunca fica
 * público por descuido.
 */

[$config, $auth] = require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function api_fail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Lê o token do header Authorization (funciona com e sem mod_rewrite). */
function api_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }

    if (preg_match('/^\s*Bearer\s+(.+)$/i', (string) $header, $matches) === 1) {
        return trim($matches[1]);
    }

    return '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    api_fail(405, 'Método não permitido. Use GET.');
}

// --- Autenticação: token de API ou sessão do portal ---
$apiToken = (string) $config['api']['token'];
$provided = api_bearer_token();
$authorized = false;

if ($apiToken !== '' && $provided !== '' && hash_equals($apiToken, $provided)) {
    $authorized = true;
} elseif ($auth->check() && $auth->canAccess('afya', $config['panels'])) {
    $authorized = true;
}

if (!$authorized) {
    header('WWW-Authenticate: Bearer');
    api_fail(401, 'Não autorizado. Envie o header Authorization: Bearer <token> ou acesse autenticado no portal.');
}

// --- Parâmetro de status ---
$statusParam = strtolower(trim((string) ($_GET['status'] ?? '')));
$statusMap = [
    '' => null,
    'todos' => null,
    'todas' => null,
    'all' => null,
    'ativa' => 'Ativa',
    'ativas' => 'Ativa',
    'ativo' => 'Ativa',
    'ativos' => 'Ativa',
    'active' => 'Ativa',
    'inativa' => 'Inativa',
    'inativas' => 'Inativa',
    'inativo' => 'Inativa',
    'inativos' => 'Inativa',
    'inactive' => 'Inativa',
];

if (!array_key_exists($statusParam, $statusMap)) {
    api_fail(400, 'Parâmetro "status" inválido. Use ativa, inativa ou todos.');
}

$status = $statusMap[$statusParam];

try {
    $repository = new BlueprintRepository(new MongoConnection(
        $config['mongo']['uri'],
        $config['mongo']['database'],
        $config['canvas']['collection']
    ));

    $blueprints = $repository->allBlueprints($status);

    $totalCourses = 0;
    $totalActive = 0;
    $totalInactive = 0;

    foreach ($blueprints as $blueprint) {
        $totalCourses += $blueprint['course_count'];
        $totalActive += $blueprint['active_count'];
        $totalInactive += $blueprint['inactive_count'];
    }

    echo json_encode([
        'ok' => true,
        'institution' => $config['lti_control']['institution_afya'],
        'filter' => $status ?? 'todos',
        'generated_at' => date('c'),
        'summary' => [
            'blueprints' => count($blueprints),
            'courses_returned' => $totalCourses,
            'courses_active' => $totalActive,
            'courses_inactive' => $totalInactive,
        ],
        'blueprints' => $blueprints,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Falha na API da AFYA: ' . $exception->getMessage());
    api_fail(500, 'Não foi possível consultar os dados no momento.');
}
