<?php

declare(strict_types=1);

[$config, $auth] = require __DIR__ . '/../src/bootstrap.php';

if (!$auth->check()) {
    redirect_to('index.php');
}

$isAdmin = $auth->isAdmin($config['admin']['users']);

const IMPORT_PREVIEW_TTL = 900; // 15 min

function admin_repository(array $config): DisciplineRepository
{
    return new DisciplineRepository(new MongoConnection(
        $config['mongo']['uri'],
        $config['mongo']['database'],
        $config['mongo']['activity_collection']
    ));
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf_token'] ?? null)) {
        flash_set('error', 'Sessão expirada. Tente novamente.');
        redirect_to('admin.php');
    }

    $action = $_POST['action'] ?? '';

    // Cancelar a pré-visualização pendente.
    if ($action === 'cancel_import') {
        unset($_SESSION['import_preview']);
        redirect_to('admin.php');
    }

    // Passo 1: enviar o arquivo e gerar a pré-visualização (sem gravar nada).
    if ($action === 'preview') {
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
            $repository = admin_repository($config);
            $importer = new ActivityImporter($config['upload']['max_rows']);

            $parsed = $importer->parseCsv((string) $file['tmp_name']);

            if ($parsed['activities'] === []) {
                flash_set('error', 'Nenhuma linha válida encontrada no arquivo (todas sem CRT ou em branco).');
                redirect_to('admin.php');
            }

            // Consulta no banco quais códigos já existem.
            $codes = array_map(static fn (array $a): string => (string) $a['codigo_disciplina'], $parsed['activities']);
            $existingSet = array_fill_keys($repository->existingCodes($codes), true);

            $newCount = 0;
            $sample = [];

            foreach ($parsed['activities'] as $index => $activity) {
                $isNew = !isset($existingSet[$activity['codigo_disciplina']]);

                if ($isNew) {
                    $newCount++;
                }

                if ($index < 8) {
                    $sample[] = [
                        'codigo_disciplina' => (string) $activity['codigo_disciplina'],
                        'nome_disciplina' => (string) $activity['nome_disciplina'],
                        'bloco' => (string) $activity['bloco'],
                        'ano' => (string) $activity['ano'],
                        'is_new' => $isNew,
                    ];
                }
            }

            $_SESSION['import_preview'] = [
                'ts' => time(),
                'file_name' => (string) $file['name'],
                'activities' => $parsed['activities'],
                'summary' => [
                    'new' => $newCount,
                    'existing' => count($parsed['activities']) - $newCount,
                    'duplicates' => $parsed['duplicates'],
                    'invalid' => $parsed['invalid'],
                    'rows' => $parsed['dataRows'],
                ],
                'sample' => $sample,
            ];
        } catch (ImportException $exception) {
            flash_set('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('Falha ao pré-visualizar planilha: ' . $exception->getMessage());
            flash_set('error', 'Não foi possível ler o arquivo. Tente novamente mais tarde.');
        }

        redirect_to('admin.php');
    }

    // Passo 2: confirmar a importação da pré-visualização.
    if ($action === 'confirm_import') {
        $preview = $_SESSION['import_preview'] ?? null;
        unset($_SESSION['import_preview']);

        if (!is_array($preview) || empty($preview['activities']) || (time() - (int) ($preview['ts'] ?? 0)) > IMPORT_PREVIEW_TTL) {
            flash_set('error', 'A pré-visualização expirou. Envie o arquivo novamente.');
            redirect_to('admin.php');
        }

        try {
            $repository = admin_repository($config);
            $newCodes = $repository->insertNewActivities($preview['activities']);
            $inserted = count($newCodes);
            $existing = count($preview['activities']) - $inserted;

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

            if (!$notified) {
                $message .= ' Atenção: não foi possível registrar/ativar todas no serviço de correção automática.';
                flash_set('error', $message);
            } else {
                flash_set('success', $message);
            }
        } catch (Throwable $exception) {
            error_log('Falha ao importar planilha: ' . $exception->getMessage());
            flash_set('error', 'Não foi possível importar o arquivo. Tente novamente mais tarde.');
        }

        redirect_to('admin.php');
    }
}

if (!$isAdmin) {
    http_response_code(403);
}

$flash = flash_get();

// Pré-visualização pendente (se ainda válida).
$preview = null;
if ($isAdmin && isset($_SESSION['import_preview']) && is_array($_SESSION['import_preview'])) {
    if ((time() - (int) ($_SESSION['import_preview']['ts'] ?? 0)) <= IMPORT_PREVIEW_TTL) {
        $preview = $_SESSION['import_preview'];
    } else {
        unset($_SESSION['import_preview']);
    }
}
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
        <?php elseif ($preview !== null): ?>
            <?php $s = $preview['summary']; ?>
            <section class="table-card" style="padding:28px">
                <h2 style="margin-top:0">Confira antes de importar</h2>
                <p class="login-copy">Arquivo: <strong><?= e($preview['file_name']) ?></strong> · <?= e($s['rows']) ?> linha(s) de dados. Nada foi gravado ainda.</p>

                <div class="import-summary" style="display:flex;gap:24px;flex-wrap:wrap;margin:18px 0">
                    <div><strong style="font-size:24px"><?= e($s['new']) ?></strong><br>linha(s) com dados <strong>novos</strong> (serão adicionadas)</div>
                    <div><strong style="font-size:24px"><?= e($s['existing']) ?></strong><br>ID(s) <strong>já cadastrados</strong> (não serão adicionados)</div>
                    <div><strong style="font-size:24px"><?= e($s['duplicates']) ?></strong><br>ID(s) repetidos no próprio arquivo (ignorados)</div>
                    <div><strong style="font-size:24px"><?= e($s['invalid']) ?></strong><br>linha(s) sem CRT (ignoradas)</div>
                </div>

                <h3>Confira o mapeamento das colunas</h3>
                <p class="login-copy">Verifique se cada valor está na coluna certa — por exemplo, <strong>ANO</strong> deve conter um ano e <strong>BLOCO</strong> o bloco. Se estiverem trocados, cancele e corrija a planilha.</p>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>CRT<br><small>→ codigo_disciplina</small></th>
                            <th>DISCIPLINA<br><small>→ nome_disciplina</small></th>
                            <th>BLOCO<br><small>→ bloco</small></th>
                            <th>ANO<br><small>→ ano</small></th>
                            <th>Situação</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($preview['sample'] as $row): ?>
                            <tr>
                                <td><code><?= e($row['codigo_disciplina']) ?></code></td>
                                <td><?= e($row['nome_disciplina'] !== '' ? $row['nome_disciplina'] : '—') ?></td>
                                <td><?= e($row['bloco'] !== '' ? $row['bloco'] : '—') ?></td>
                                <td><?= e($row['ano'] !== '' ? $row['ano'] : '—') ?></td>
                                <td>
                                    <span class="status-badge <?= $row['is_new'] ? 'is-active' : 'is-inactive' ?>">
                                        <?= $row['is_new'] ? 'Novo' : 'Já existe' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($s['rows'] > count($preview['sample'])): ?>
                    <p class="login-copy"><small>Mostrando as primeiras <?= e(count($preview['sample'])) ?> linhas de <?= e($s['rows']) ?>.</small></p>
                <?php endif; ?>

                <div class="filter-actions" style="margin-top:20px;display:flex;gap:10px">
                    <form method="post">
                        <input type="hidden" name="action" value="confirm_import">
                        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                        <button class="btn btn-primary" type="submit"<?= $s['new'] === 0 ? ' disabled' : '' ?>>Confirmar e importar <?= e($s['new']) ?> nova(s)</button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="action" value="cancel_import">
                        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                        <button class="btn btn-ghost" type="submit">Cancelar</button>
                    </form>
                </div>
            </section>
        <?php else: ?>
            <section class="table-card" style="padding:28px">
                <h2 style="margin-top:0">Importar disciplinas por planilha</h2>
                <p class="login-copy">
                    Envie um arquivo <strong>CSV separado por ponto-e-vírgula</strong> (Excel &rarr; Salvar como &rarr; CSV).
                    Você verá uma <strong>pré-visualização</strong> (quantas são novas, quantas já existem e o mapeamento das colunas) antes de gravar qualquer coisa.
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
                    <input type="hidden" name="action" value="preview">
                    <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?= e($config['upload']['max_bytes']) ?>">
                    <div class="field search-field">
                        <label for="spreadsheet">Arquivo CSV</label>
                        <input class="input" id="spreadsheet" name="spreadsheet" type="file" accept=".csv,text/csv,text/plain" required>
                    </div>
                    <div class="filter-actions">
                        <button class="btn btn-primary" type="submit">Pré-visualizar</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
