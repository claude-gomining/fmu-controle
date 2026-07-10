<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/MongoConnection.php';
require_once __DIR__ . '/../src/DisciplineRepository.php';
require_once __DIR__ . '/../src/UserRepository.php';
require_once __DIR__ . '/../src/LoginRateLimiter.php';

$config = require __DIR__ . '/../config/config.php';

date_default_timezone_set($config['timezone']);
session_name($config['session_name']);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
send_security_headers();

$auth = new Auth();
$error = null;
$repository = null;

try {
    if ($auth->check()) {
        $connection = new MongoConnection(
            $config['mongo']['uri'],
            $config['mongo']['database'],
            $config['mongo']['activity_collection']
        );
        $repository = new DisciplineRepository($connection);
    }
} catch (Throwable $exception) {
    error_log('Falha ao conectar ao MongoDB: ' . $exception->getMessage());
    $error = 'Não foi possível conectar ao banco de dados. Tente novamente mais tarde.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf($_POST['_csrf_token'] ?? null)) {
        flash_set('error', 'Sessão expirada. Tente novamente.');
        redirect_to('index.php');
    }

    if ($action === 'login') {
        $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

        $userLimiter = new LoginRateLimiter(
            $config['security']['throttle_dir'],
            $config['security']['login_max_attempts'],
            $config['security']['login_lockout_seconds']
        );
        $ipLimiter = new LoginRateLimiter(
            $config['security']['throttle_dir'],
            $config['security']['login_ip_max_attempts'],
            $config['security']['login_lockout_seconds']
        );
        $userKey = 'user|' . mb_strtolower($username) . '|' . client_ip();
        $ipKey = 'ip|' . client_ip();

        if ($userLimiter->isBlocked($userKey) || $ipLimiter->isBlocked($ipKey)) {
            flash_set('error', 'Muitas tentativas de login. Aguarde alguns minutos e tente novamente.');
            redirect_to('index.php');
        }

        try {
            $userConnection = new MongoConnection(
                $config['mongo']['uri'],
                $config['mongo']['database'],
                $config['mongo']['user_collection']
            );
            $users = new UserRepository($userConnection);
            $verifiedUser = $users->verifyCredentials($username, $password);

            if ($verifiedUser !== null) {
                $userLimiter->clear($userKey);
                $ipLimiter->clear($ipKey);
                $auth->loginAs($verifiedUser);
                redirect_to('index.php');
            }
        } catch (Throwable $exception) {
            error_log('Falha ao validar login: ' . $exception->getMessage());
            flash_set('error', 'Não foi possível validar o login. Tente novamente mais tarde.');
            redirect_to('index.php');
        }

        $userLimiter->registerFailure($userKey);
        $ipLimiter->registerFailure($ipKey);
        flash_set('error', 'Usuário ou senha inválidos.');
        redirect_to('index.php');
    }

    if ($action === 'logout') {
        $auth->logout();
        redirect_to('index.php');
    }

    if ($action === 'update_status' && $auth->check()) {
        if (!$repository) {
            flash_set('error', 'Não foi possível conectar ao MongoDB.');
            redirect_to('index.php');
        }

        $targetStatus = (string) ($_POST['target_status'] ?? '');
        $allowedStatuses = ['Ativa', 'Inativa'];

        if (!in_array($targetStatus, $allowedStatuses, true)) {
            flash_set('error', 'Status inválido.');
            redirect_to(current_url());
        }

        $selectedIds = $_POST['discipline_ids'] ?? [];
        $selectedIds = is_array($selectedIds) ? $selectedIds : [];
        $modifiedCount = $repository->updateStatus($selectedIds, $targetStatus);

        flash_set('success', $modifiedCount . ' disciplina(s) atualizada(s).');
        redirect_to(current_url());
    }
}

$flash = flash_get();
$criteria = [
    'search' => trim((string) ($_GET['q'] ?? '')),
    'block' => trim((string) ($_GET['bloco'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$pagination = ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
$blocks = [];

if ($auth->check() && $repository && !$error) {
    try {
        $pagination = $repository->paginate($criteria, $page, $perPage);
        $blocks = $repository->distinctBlocks();
    } catch (Throwable $exception) {
        error_log('Falha ao consultar disciplinas: ' . $exception->getMessage());
        $error = 'Não foi possível carregar as disciplinas. Tente novamente mais tarde.';
    }
}

$totalPages = max(1, (int) ceil($pagination['total'] / $perPage));
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($config['app_name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<?php if (!$auth->check()): ?>
    <main class="login-shell">
        <section class="login-panel" aria-labelledby="login-title">
            <div class="brand-mark">
                <span>FMU</span>
                <small>Gomining</small>
            </div>
            <p class="eyebrow">Correção automática</p>
            <h1 id="login-title">Acesso ao portal</h1>
            <p class="login-copy">Entre com as credenciais enviadas para administrar as disciplinas.</p>

            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <form method="post" class="login-form">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">

                <label for="username">Usuário</label>
                <input class="input" id="username" name="username" type="text" autocomplete="username" required autofocus>

                <label for="password">Senha</label>
                <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>

                <button class="btn btn-primary full" type="submit">Entrar</button>
            </form>
        </section>
    </main>
<?php else: ?>
    <div class="app-shell">
        <aside class="sidebar">
            <div class="sidebar-logo">
                <div class="logo-text">FMU<span>.</span></div>
                <div class="logo-sub">Correção automática</div>
            </div>
            <nav aria-label="Navegação principal">
                <a class="nav-link active" href="index.php">Disciplinas</a>
            </nav>
            <form method="post" class="logout-form">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                <button class="btn btn-ghost full" type="submit">Sair</button>
            </form>
        </aside>

        <main class="content">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Portal FMU</p>
                    <h1>Disciplinas de correção automática</h1>
                </div>
                <div class="user-chip"><?= e($auth->user()) ?></div>
            </header>

            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <section class="toolbar" aria-label="Filtros">
                <form method="get" class="filters-form">
                    <div class="field search-field">
                        <label for="q">Busca</label>
                        <input class="input" id="q" name="q" type="search" value="<?= e($criteria['search']) ?>" placeholder="Nome ou código da disciplina">
                    </div>
                    <div class="field">
                        <label for="bloco">Bloco</label>
                        <select class="input" id="bloco" name="bloco">
                            <option value="">Todos</option>
                            <?php foreach ($blocks as $block): ?>
                                <option value="<?= e($block) ?>" <?= $criteria['block'] === $block ? 'selected' : '' ?>><?= e($block) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="status">Status</label>
                        <select class="input" id="status" name="status">
                            <option value="">Todos</option>
                            <option value="Ativa" <?= $criteria['status'] === 'Ativa' ? 'selected' : '' ?>>Ativa</option>
                            <option value="Inativa" <?= $criteria['status'] === 'Inativa' ? 'selected' : '' ?>>Inativa</option>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button class="btn btn-secondary" type="submit">Filtrar</button>
                        <a class="btn btn-ghost" href="index.php">Limpar</a>
                    </div>
                </form>
            </section>

            <form method="post" class="list-form">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">

                <section class="bulk-actions" aria-label="Ações em massa">
                    <p><?= e($pagination['total']) ?> disciplina(s) encontrada(s)</p>
                    <div class="bulk-buttons">
                        <button class="btn btn-primary btn-sm" name="target_status" value="Ativa" type="submit">Ativar selecionadas</button>
                        <button class="btn btn-outline btn-sm" name="target_status" value="Inativa" type="submit">Desativar selecionadas</button>
                    </div>
                </section>

                <section class="table-card">
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th class="select-col">
                                    <input type="checkbox" aria-label="Selecionar todas" data-select-all>
                                </th>
                                <th>Nome da disciplina</th>
                                <th>Bloco</th>
                                <th>Ano</th>
                                <th>Código da disciplina</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($pagination['items'] === []): ?>
                                <tr>
                                    <td colspan="6" class="empty-state">Nenhuma disciplina encontrada.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($pagination['items'] as $discipline): ?>
                                <?php $isActive = strtolower($discipline['status']) === 'ativa' || strtolower($discipline['status']) === 'ativo'; ?>
                                <tr>
                                    <td class="select-col">
                                        <input type="checkbox" name="discipline_ids[]" value="<?= e($discipline['id']) ?>" aria-label="Selecionar <?= e($discipline['nome_disciplina']) ?>">
                                    </td>
                                    <td class="name-cell"><?= e($discipline['nome_disciplina']) ?></td>
                                    <td><?= e($discipline['bloco']) ?></td>
                                    <td><?= e($discipline['ano']) ?></td>
                                    <td><code><?= e($discipline['codigo_disciplina']) ?></code></td>
                                    <td>
                                        <span class="status-badge <?= $isActive ? 'is-active' : 'is-inactive' ?>">
                                            <?= e($discipline['status'] ?: 'Sem status') ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </form>

            <nav class="pagination" aria-label="Paginação">
                <a class="btn btn-ghost btn-sm <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e($page <= 1 ? '#' : current_url(['page' => $page - 1])) ?>">Anterior</a>
                <span>Página <?= e($page) ?> de <?= e($totalPages) ?></span>
                <a class="btn btn-ghost btn-sm <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e($page >= $totalPages ? '#' : current_url(['page' => $page + 1])) ?>">Próxima</a>
            </nav>
        </main>
    </div>
    <script src="assets/app.js"></script>
<?php endif; ?>
</body>
</html>
