<?php

declare(strict_types=1);

[$config, $auth] = require __DIR__ . '/../src/bootstrap.php';

require_once __DIR__ . '/../src/CanvasException.php';
require_once __DIR__ . '/../src/CanvasClient.php';
require_once __DIR__ . '/../src/BlueprintRepository.php';

if (!$auth->check()) {
    redirect_to('index.php');
}

$canvasCollection = $config['canvas']['collection'];

function canvas_repository(array $config): BlueprintRepository
{
    $connection = new MongoConnection(
        $config['mongo']['uri'],
        $config['mongo']['database'],
        $config['canvas']['collection']
    );

    return new BlueprintRepository($connection);
}

function canvas_client(array $config): CanvasClient
{
    return new CanvasClient(
        $config['canvas']['base_url'],
        $config['canvas']['token'],
        $config['canvas']['timeout_seconds'],
        $config['canvas']['per_page'],
        $config['canvas']['max_pages']
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf_token'] ?? null)) {
        flash_set('error', 'Sessão expirada. Tente novamente.');
        redirect_to('canvas.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'register_blueprint' || $action === 'refresh_blueprint') {
        $blueprintId = trim((string) ($_POST['blueprint_id'] ?? ''));

        if ($blueprintId === '' || !ctype_digit($blueprintId)) {
            flash_set('error', 'Informe um código de blueprint válido (apenas números).');
            redirect_to('canvas.php');
        }

        try {
            $repository = canvas_repository($config);

            if ($action === 'refresh_blueprint' && !$repository->blueprintExists($blueprintId)) {
                flash_set('error', 'Blueprint não encontrada no cadastro. Registre-a primeiro.');
                redirect_to('canvas.php');
            }

            $courses = canvas_client($config)->fetchAssociatedCourses($blueprintId);
            $result = $repository->saveBlueprintCourses($blueprintId, $config['canvas']['base_url'], $courses);

            $message = $action === 'register_blueprint'
                ? sprintf('Blueprint %s cadastrada: %d curso(s) coletado(s).', $blueprintId, $result['total'])
                : sprintf('Blueprint %s atualizada: %d curso(s) novo(s), %d no total.', $blueprintId, $result['added'], $result['total']);

            flash_set('success', $message);
        } catch (CanvasException $exception) {
            flash_set('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('Falha ao processar blueprint do Canvas: ' . $exception->getMessage());
            flash_set('error', 'Não foi possível concluir a operação. Tente novamente mais tarde.');
        }

        redirect_to('canvas.php');
    }

    if ($action === 'toggle_courses') {
        $blueprintId = trim((string) ($_POST['blueprint_id'] ?? ''));
        $targetStatus = (string) ($_POST['target_status'] ?? '');
        $allowedStatuses = ['Ativa', 'Inativa'];

        if ($blueprintId === '' || !ctype_digit($blueprintId)) {
            flash_set('error', 'Blueprint inválida.');
            redirect_to(current_url_canvas());
        }

        if (!in_array($targetStatus, $allowedStatuses, true)) {
            flash_set('error', 'Status inválido.');
            redirect_to(current_url_canvas());
        }

        $courseIds = $_POST['course_ids'] ?? [];
        $courseIds = is_array($courseIds) ? array_map('intval', $courseIds) : [];

        try {
            canvas_repository($config)->updateCoursesStatus($blueprintId, $courseIds, $targetStatus);
            $scope = $courseIds === [] ? 'todos os cursos da blueprint' : count($courseIds) . ' curso(s)';
            flash_set('success', ucfirst($scope) . ' atualizado(s) para "' . $targetStatus . '".');
        } catch (Throwable $exception) {
            error_log('Falha ao atualizar status de cursos: ' . $exception->getMessage());
            flash_set('error', 'Não foi possível atualizar os cursos. Tente novamente mais tarde.');
        }

        redirect_to(current_url_canvas());
    }
}

function current_url_canvas(array $override = []): string
{
    $query = array_merge($_GET, $override);

    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    $queryString = http_build_query($query);

    return 'canvas.php' . ($queryString ? '?' . $queryString : '');
}

$flash = flash_get();
$filter = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = $config['canvas']['page_size'];
$error = null;
$pagination = ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];

try {
    $pagination = canvas_repository($config)->paginate($filter, $page, $perPage);
} catch (Throwable $exception) {
    error_log('Falha ao listar blueprints do Canvas: ' . $exception->getMessage());
    $error = 'Não foi possível carregar as blueprints. Tente novamente mais tarde.';
}

$totalPages = max(1, (int) ceil($pagination['total'] / $perPage));
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Canvas · Controle de cursos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/canvas.css">
</head>
<body class="cv-body">
<div id="cv-overlay" class="cv-overlay" hidden>
    <div class="cv-overlay-card">
        <div class="cv-spinner" aria-hidden="true"></div>
        <p class="cv-overlay-title">Aguarde…</p>
        <p class="cv-overlay-sub">Buscando os cursos no Canvas e gravando os dados. Isso pode levar alguns instantes.</p>
    </div>
</div>

<header class="cv-topbar">
    <div class="cv-brand">
        <span class="cv-brand-mark">C</span>
        <div>
            <strong>Canvas · Controle de cursos</strong>
            <small>Correção por blueprint</small>
        </div>
    </div>
    <div class="cv-topbar-actions">
        <a class="cv-link" href="index.php">Portal FMU</a>
        <span class="cv-user"><?= e($auth->user()) ?></span>
        <form method="post" action="index.php" class="cv-inline-form">
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <button class="cv-btn cv-btn-ghost" type="submit">Sair</button>
        </form>
    </div>
</header>

<main class="cv-main">
    <?php if ($flash): ?>
        <div class="cv-alert cv-alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="cv-alert cv-alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="cv-card cv-register">
        <div>
            <h2>Cadastrar blueprint</h2>
            <p>Informe o código (ID do curso da blueprint no Canvas). Os cursos associados serão buscados e cadastrados.</p>
        </div>
        <form method="post" class="cv-register-form" data-loading>
            <input type="hidden" name="action" value="register_blueprint">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <input class="cv-input" name="blueprint_id" inputmode="numeric" pattern="[0-9]+" placeholder="Ex.: 130764" required>
            <button class="cv-btn cv-btn-primary" type="submit">Cadastrar e buscar</button>
        </form>
    </section>

    <section class="cv-toolbar">
        <form method="get" class="cv-filter-form">
            <input class="cv-input" type="search" name="q" value="<?= e($filter) ?>" placeholder="Filtrar cursos por ID ou nome">
            <button class="cv-btn cv-btn-secondary" type="submit">Filtrar</button>
            <?php if ($filter !== ''): ?>
                <a class="cv-btn cv-btn-ghost" href="canvas.php">Limpar</a>
            <?php endif; ?>
        </form>
        <span class="cv-count"><?= e($pagination['total']) ?> blueprint(s)</span>
    </section>

    <?php if ($pagination['items'] === []): ?>
        <div class="cv-card cv-empty">
            <?= $filter !== '' ? 'Nenhuma blueprint com cursos correspondentes ao filtro.' : 'Nenhuma blueprint cadastrada ainda. Cadastre a primeira acima.' ?>
        </div>
    <?php endif; ?>

    <?php foreach ($pagination['items'] as $blueprint): ?>
        <section class="cv-card cv-blueprint">
            <header class="cv-blueprint-head">
                <div>
                    <span class="cv-badge">Blueprint</span>
                    <h3><?= e($blueprint['blueprint_id']) ?></h3>
                    <small><?= e($blueprint['course_count']) ?> curso(s)<?= $blueprint['updated_at'] !== '' ? ' · última coleta ' . e($blueprint['updated_at']) : '' ?></small>
                </div>
                <form method="post" class="cv-inline-form" data-loading>
                    <input type="hidden" name="action" value="refresh_blueprint">
                    <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="blueprint_id" value="<?= e($blueprint['blueprint_id']) ?>">
                    <button class="cv-btn cv-btn-secondary" type="submit">Atualizar</button>
                </form>
            </header>

            <form method="post" class="cv-course-form">
                <input type="hidden" name="action" value="toggle_courses">
                <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="blueprint_id" value="<?= e($blueprint['blueprint_id']) ?>">

                <div class="cv-bulk">
                    <label class="cv-check"><input type="checkbox" data-select-all> Selecionar todos</label>
                    <div class="cv-bulk-actions">
                        <button class="cv-btn cv-btn-primary cv-btn-sm" type="submit" name="target_status" value="Ativa">Ativar selecionados</button>
                        <button class="cv-btn cv-btn-outline cv-btn-sm" type="submit" name="target_status" value="Inativa">Desativar selecionados</button>
                    </div>
                </div>

                <?php if ($blueprint['courses'] === []): ?>
                    <p class="cv-empty-inline">Nenhum curso <?= $filter !== '' ? 'corresponde ao filtro' : 'coletado' ?>.</p>
                <?php else: ?>
                    <div class="cv-table-wrap">
                        <table class="cv-table">
                            <thead>
                            <tr>
                                <th class="cv-col-check"></th>
                                <th>Curso</th>
                                <th>ID do curso</th>
                                <th>Termo</th>
                                <th>Coletado em</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($blueprint['courses'] as $course): ?>
                                <?php $isActive = strtolower($course['status']) === 'ativa'; ?>
                                <tr>
                                    <td class="cv-col-check">
                                        <input type="checkbox" name="course_ids[]" value="<?= e($course['course_id']) ?>" aria-label="Selecionar curso <?= e($course['course_id']) ?>">
                                    </td>
                                    <td class="cv-course-name"><?= e($course['name'] !== '' ? $course['name'] : '—') ?></td>
                                    <td><code><?= e($course['course_id']) ?></code><?= $course['sis_course_id'] !== '' ? ' <small>· SIS ' . e($course['sis_course_id']) . '</small>' : '' ?></td>
                                    <td><?= e($course['term_name'] !== '' ? $course['term_name'] : '—') ?></td>
                                    <td><?= e($course['collected_at'] !== '' ? $course['collected_at'] : '—') ?></td>
                                    <td><span class="cv-status <?= $isActive ? 'is-active' : 'is-inactive' ?>"><?= e($course['status'] !== '' ? $course['status'] : 'Sem status') ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </form>
        </section>
    <?php endforeach; ?>

    <?php if ($totalPages > 1): ?>
        <nav class="cv-pagination" aria-label="Paginação">
            <a class="cv-btn cv-btn-ghost cv-btn-sm <?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= e($page <= 1 ? '#' : current_url_canvas(['page' => $page - 1])) ?>">Anterior</a>
            <span>Página <?= e($page) ?> de <?= e($totalPages) ?></span>
            <a class="cv-btn cv-btn-ghost cv-btn-sm <?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= e($page >= $totalPages ? '#' : current_url_canvas(['page' => $page + 1])) ?>">Próxima</a>
        </nav>
    <?php endif; ?>
</main>

<script src="assets/canvas.js"></script>
</body>
</html>
