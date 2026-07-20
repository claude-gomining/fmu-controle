<?php

declare(strict_types=1);

[$config, $auth] = require __DIR__ . '/../src/bootstrap.php';

if (!$auth->check()) {
    redirect_to('index.php');
}

$isAdmin = $auth->isAdmin($config['admin']['users']);

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf_token'] ?? null)) {
        flash_set('error', 'Sessão expirada. Tente novamente.');
        redirect_to('admin.php');
    }

    if (($_POST['action'] ?? '') === 'import') {
        $file = $_FILES['spreadsheet'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash_set('error', 'Selecione um arquivo CSV para enviar.');
            redirect_to('admin.php');
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_FORM_SIZE) {
            flash_set('error', 'Arquivo muito grande.');
            redirect_to('admin.php');
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            flash_set('error', 'Falha no upload do arquivo. Tente novamente.');
            redirect_to('admin.php');
        }

        if ((int) $file['size'] > $config['upload']['max_bytes']) {
            flash_set('error', 'Arquivo muito grande. Limite de ' . (int) round($config['upload']['max_bytes'] / 1048576) . ' MB.');
            redirect_to('admin.php');
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, ['csv', 'txt'], true)) {
            flash_set('error', 'Formato não suportado. Envie um arquivo CSV separado por ponto-e-vírgula.');
            redirect_to('admin.php');
        }

        try {
            $connection = new MongoConnection(
                $config['mongo']['uri'],
                $config['mongo']['database'],
                $config['mongo']['activity_collection']
            );
            $repository = new DisciplineRepository($connection);
            $importer = new ActivityImporter($config['upload']['max_rows']);

            $parsed = $importer->parseCsv((string) $file['tmp_name']);
            $newCodes = $repository->insertNewActivities($parsed['activities']);
            $inserted = count($newCodes);
            $existing = count($parsed['activities']) - $inserted;

            // Atividades novas: primeiro criar no serviço LTI, depois ativar.
            $notified = true;

            if ($newCodes !== []) {
                $notifier = new ActivityControlNotifier(
                    $config['lti_control']['base_url'],
                    $config['lti_control']['institution'],
                    $config['lti_control']['timeout_seconds']
                );
                $created = $notifier->notifyCreated($newCodes);
                $enabled = $notifier->notifyEnabled($newCodes);
                $notified = $created && $enabled;
            }

            $message = sprintf('%d disciplina(s) adicionada(s); %d já cadastrada(s).', $inserted, $existing);

            if ($parsed['invalid'] > 0) {
                $message .= sprintf(' %d linha(s) sem CRT ignorada(s).', $parsed['invalid']);
            }

            if ($parsed['duplicates'] > 0) {
                $message .= sprintf(' %d CRT(s) repetido(s) no arquivo.', $parsed['duplicates']);
            }

            if (!$notified) {
                $message .= ' Atenção: não foi possível registrar/ativar todas no serviço de correção automática.';
                flash_set('error', $message);
            } else {
                flash_set('success', $message);
            }
        } catch (ImportException $exception) {
            flash_set('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('Falha na importação de planilha: ' . $exception->getMessage());
            flash_set('error', 'Não foi possível importar o arquivo. Tente novamente mais tarde.');
        }

        redirect_to('admin.php');
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
    <title>Administração · <?= e($config['app_name']) ?></title>
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
                <a class="nav-link active" href="admin.php">Administração</a>
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
                <h1>Administração</h1>
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
                <h2 style="margin-top:0">Importar disciplinas por planilha</h2>
                <p class="login-copy">
                    Envie um arquivo <strong>CSV separado por ponto-e-vírgula</strong> (Excel &rarr; Salvar como &rarr; CSV).
                    Disciplinas cujo CRT ainda não estiver cadastrado serão adicionadas com status <strong>Ativa</strong>.
                    As já existentes (mesmo CRT) não são alteradas.
                </p>
                <p class="login-copy">
                    A primeira linha deve ser exatamente o cabeçalho:
                </p>
                <p><code>CRT;DISCIPLINA;BLOCO;ANO</code></p>
                <p class="login-copy">
                    Onde <strong>CRT</strong> é o nome/código da oferta (usado como identificador único),
                    <strong>DISCIPLINA</strong> o nome, <strong>BLOCO</strong> o bloco e <strong>ANO</strong> o ano.
                    Cada linha corresponde a uma disciplina; os espaços em branco no início e fim de cada célula são removidos.
                </p>

                <form method="post" enctype="multipart/form-data" class="filters-form" style="margin-top:20px">
                    <input type="hidden" name="action" value="import">
                    <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?= e($config['upload']['max_bytes']) ?>">
                    <div class="field search-field">
                        <label for="spreadsheet">Arquivo CSV</label>
                        <input class="input" id="spreadsheet" name="spreadsheet" type="file" accept=".csv,text/csv,text/plain" required>
                    </div>
                    <div class="filter-actions">
                        <button class="btn btn-primary" type="submit">Importar</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
