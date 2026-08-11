<?php

declare(strict_types=1);

[$config, $auth] = require __DIR__ . '/../src/bootstrap.php';

if (!$auth->check()) {
    redirect_to('index.php');
}

$isAdmin = $auth->isAdmin($config['admin']['users']);

// Valores preservados quando o formulário volta com erro.
$old = ['codigo' => '', 'nome' => '', 'bloco' => '', 'ano' => '', 'status' => 'Ativa'];

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf_token'] ?? null)) {
        flash_set('error', 'Sessão expirada. Tente novamente.');
        redirect_to('nova-disciplina.php');
    }

    $codigo = trim((string) ($_POST['codigo_disciplina'] ?? ''));
    $nome = trim((string) ($_POST['nome_disciplina'] ?? ''));
    $bloco = trim((string) ($_POST['bloco'] ?? ''));
    $ano = trim((string) ($_POST['ano'] ?? ''));
    $status = ($_POST['status'] ?? 'Ativa') === 'Inativa' ? 'Inativa' : 'Ativa';

    $old = ['codigo' => $codigo, 'nome' => $nome, 'bloco' => $bloco, 'ano' => $ano, 'status' => $status];
    $error = null;

    if ($codigo === '') {
        $error = 'Informe o CRT (código da disciplina).';
    } elseif (preg_match('/\s/u', $codigo) === 1) {
        $error = 'O CRT não pode conter espaços.';
    }

    if ($error !== null) {
        flash_set('error', $error);
    } else {
        try {
            $repository = new DisciplineRepository(new MongoConnection(
                $config['mongo']['uri'],
                $config['mongo']['database'],
                $config['mongo']['activity_collection']
            ));

            $inserted = $repository->insertSingleActivity($codigo, $status, $nome, $bloco, $ano);

            if (!$inserted) {
                flash_set('error', sprintf('O CRT "%s" já está cadastrado. Nada foi alterado.', $codigo));
            } else {
                $notifier = new ActivityControlNotifier(
                    $config['lti_control']['base_url'],
                    $config['lti_control']['institution'],
                    $config['lti_control']['timeout_seconds'],
                    $config['lti_control']['batch_size']
                );
                $failed = $notifier->registerAndApply([$codigo], $status === 'Ativa');

                $message = sprintf('Disciplina "%s" cadastrada como %s.', $codigo, $status);

                if ($failed === []) {
                    flash_set('success', $message);
                    redirect_to('nova-disciplina.php');
                }

                flash_set('error', $message . ' Atenção: não foi possível registrá-la no serviço de correção automática.');
            }

            redirect_to('nova-disciplina.php');
        } catch (Throwable $exception) {
            error_log('Falha ao cadastrar disciplina individual: ' . $exception->getMessage());
            flash_set('error', 'Não foi possível cadastrar a disciplina. Tente novamente mais tarde.');
        }
    }
}

if (!$isAdmin) {
    http_response_code(403);
}

$flash = flash_get();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nova disciplina · <?= e($config['app_name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="sidebar-logo">
            <div class="logo-text">FMU<span>.</span></div>
            <div class="logo-sub">Correção automática</div>
        </div>
        <nav aria-label="Navegação principal">
            <a class="nav-link" href="index.php">Disciplinas</a>
            <?php if ($isAdmin): ?>
                <a class="nav-link active" href="nova-disciplina.php">Nova disciplina</a>
                <a class="nav-link" href="admin.php">Administração</a>
            <?php endif; ?>
        </nav>
        <form method="post" action="index.php" class="logout-form">
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
            <button class="btn btn-ghost full" type="submit">Sair</button>
        </form>
    </aside>

    <main class="content">
        <header class="topbar">
            <div>
                <p class="eyebrow">Portal FMU</p>
                <h1>Nova disciplina</h1>
            </div>
            <div class="user-chip"><?= e($auth->user()) ?></div>
        </header>

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$isAdmin): ?>
            <section class="table-card">
                <div class="empty-state" style="padding:32px">
                    Acesso restrito. Esta área está disponível apenas para a administração da Gomining.
                </div>
            </section>
        <?php else: ?>
            <section class="table-card" style="padding:28px">
                <h2 style="margin-top:0">Cadastrar uma disciplina</h2>
                <p class="login-copy">
                    Apenas o <strong>CRT</strong> é obrigatório — os demais campos podem ficar em branco
                    (aparecem como &ldquo;—&rdquo; na listagem e podem ser preenchidos depois por importação).
                    O CRT não pode conter espaços.
                </p>

                <form method="post" style="margin-top:20px;max-width:560px">
                    <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">

                    <div class="field" style="margin-bottom:16px">
                        <label for="codigo_disciplina">CRT (código da disciplina) <strong>*</strong></label>
                        <input class="input" id="codigo_disciplina" name="codigo_disciplina" type="text"
                               value="<?= e($old['codigo']) ?>" placeholder="Ex.: 242GGR0265A"
                               pattern="\S+" title="O CRT não pode conter espaços." required autofocus>
                    </div>

                    <div class="field" style="margin-bottom:16px">
                        <label for="nome_disciplina">Nome da disciplina <small>(opcional)</small></label>
                        <input class="input" id="nome_disciplina" name="nome_disciplina" type="text"
                               value="<?= e($old['nome']) ?>" placeholder="Ex.: AÇÕES INTEGRADAS">
                    </div>

                    <div style="display:flex;gap:16px;flex-wrap:wrap">
                        <div class="field" style="margin-bottom:16px;flex:1;min-width:160px">
                            <label for="bloco">Bloco <small>(opcional)</small></label>
                            <input class="input" id="bloco" name="bloco" type="text"
                                   value="<?= e($old['bloco']) ?>" placeholder="Ex.: 1">
                        </div>
                        <div class="field" style="margin-bottom:16px;flex:1;min-width:160px">
                            <label for="ano">Ano <small>(opcional)</small></label>
                            <input class="input" id="ano" name="ano" type="text" inputmode="numeric"
                                   value="<?= e($old['ano']) ?>" placeholder="Ex.: 2026">
                        </div>
                    </div>

                    <div class="field" style="margin-bottom:20px;max-width:220px">
                        <label for="status">Status inicial</label>
                        <select class="input" id="status" name="status">
                            <option value="Ativa"<?= $old['status'] === 'Ativa' ? ' selected' : '' ?>>Ativa</option>
                            <option value="Inativa"<?= $old['status'] === 'Inativa' ? ' selected' : '' ?>>Inativa</option>
                        </select>
                    </div>

                    <div class="filter-actions" style="display:flex;gap:10px">
                        <button class="btn btn-primary" type="submit">Cadastrar disciplina</button>
                        <a class="btn btn-ghost" href="index.php">Voltar para a lista</a>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
