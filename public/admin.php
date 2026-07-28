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

function admin_notifier(array $config): ActivityControlNotifier
{
    return new ActivityControlNotifier(
        $config['lti_control']['base_url'],
        $config['lti_control']['institution'],
        $config['lti_control']['timeout_seconds'],
        $config['lti_control']['batch_size']
    );
}

/**
 * Valida o upload de um CSV e devolve o caminho temporário; em caso de erro,
 * grava a mensagem e redireciona (não retorna).
 */
function admin_uploaded_csv_path(mixed $file, array $config): string
{
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
        flash_set('error', 'Formato não suportado. Envie um arquivo CSV (.csv ou .txt).');
        redirect_to('admin.php');
    }

    return (string) $file['tmp_name'];
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf_token'] ?? null)) {
        flash_set('error', 'Sessão expirada. Tente novamente.');
        redirect_to('admin.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'cancel_import') {
        unset($_SESSION['import_preview']);
        redirect_to('admin.php');
    }

    if ($action === 'cancel_codes') {
        unset($_SESSION['import_codes_preview']);
        redirect_to('admin.php');
    }

    // Reenvia ao serviço LTI os IDs cujo lote falhou.
    if ($action === 'retry_failed') {
        $ids = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($_POST['ids'] ?? ''))
        ), static fn (string $id): bool => $id !== ''));
        $enable = ($_POST['enable'] ?? '1') === '1';

        if ($ids === []) {
            flash_set('error', 'Nenhum ID para reenviar.');
            redirect_to('admin.php');
        }

        try {
            $stillFailed = admin_notifier($config)->registerAndApply($ids, $enable);

            if ($stillFailed === []) {
                flash_set('success', sprintf('%d ID(s) enviados com sucesso ao serviço de correção automática.', count($ids)));
            } else {
                $_SESSION['last_failed_ids'] = ['ids' => $stillFailed, 'enable' => $enable];
                flash_set('error', sprintf(
                    '%d de %d ID(s) ainda falharam no serviço de correção automática.',
                    count($stillFailed),
                    count($ids)
                ));
            }
        } catch (Throwable $exception) {
            error_log('Falha ao reenviar IDs ao serviço LTI: ' . $exception->getMessage());
            flash_set('error', 'Não foi possível reenviar. Tente novamente mais tarde.');
        }

        redirect_to('admin.php');
    }

    // ----- Planilha completa (CRT;DISCIPLINA;BLOCO;ANO): pré-visualização -----
    if ($action === 'preview') {
        $tmpPath = admin_uploaded_csv_path($_FILES['spreadsheet'] ?? null, $config);

        try {
            $repository = admin_repository($config);
            $importer = new ActivityImporter($config['upload']['max_rows']);
            $parsed = $importer->parseCsv($tmpPath);

            if ($parsed['activities'] === [] && $parsed['rejected'] === []) {
                flash_set('error', 'Nenhuma linha válida encontrada no arquivo (todas em branco).');
                redirect_to('admin.php');
            }

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
                'file_name' => (string) ($_FILES['spreadsheet']['name'] ?? 'arquivo.csv'),
                'activities' => $parsed['activities'],
                'summary' => [
                    'new' => $newCount,
                    'existing' => count($parsed['activities']) - $newCount,
                    'duplicates' => $parsed['duplicates'],
                    'rejected' => count($parsed['rejected']),
                    'rows' => $parsed['dataRows'],
                ],
                'sample' => $sample,
                'rejected' => $parsed['rejected'],
            ];
        } catch (ImportException $exception) {
            flash_set('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('Falha ao pré-visualizar planilha: ' . $exception->getMessage());
            flash_set('error', 'Não foi possível ler o arquivo. Tente novamente mais tarde.');
        }

        redirect_to('admin.php');
    }

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

            // Atividades novas: primeiro criar no serviço LTI, depois ativar (em lotes).
            $failedIds = [];

            if ($newCodes !== []) {
                $failedIds = admin_notifier($config)->registerAndApply($newCodes, true);
            }

            $rejected = is_array($preview['rejected'] ?? null) ? $preview['rejected'] : [];

            if ($rejected !== []) {
                $_SESSION['last_rejected'] = $rejected;
            }

            if ($failedIds !== []) {
                $_SESSION["last_failed_ids"] = ["ids" => $failedIds, "enable" => true];
            }

            $message = sprintf('%d disciplina(s) adicionada(s); %d já cadastrada(s).', $inserted, $existing);

            if ($rejected !== []) {
                $message .= sprintf(' %d linha(s) não importada(s) por CRT inválido.', count($rejected));
            }

            if ($failedIds !== []) {
                flash_set('error', $message . sprintf(
                    ' Atenção: %d de %d não puderam ser registradas/ativadas no serviço de correção automática (veja a lista abaixo).',
                    count($failedIds),
                    count($newCodes)
                ));
            } else {
                flash_set('success', $message);
            }
        } catch (Throwable $exception) {
            error_log('Falha ao importar planilha: ' . $exception->getMessage());
            flash_set('error', 'Não foi possível importar o arquivo. Tente novamente mais tarde.');
        }

        redirect_to('admin.php');
    }

    // ----- Lista de CRT como Inativa: pré-visualização -----
    if ($action === 'preview_codes') {
        $tmpPath = admin_uploaded_csv_path($_FILES['codes'] ?? null, $config);

        try {
            $repository = admin_repository($config);
            $importer = new ActivityImporter($config['upload']['max_rows']);
            $parsed = $importer->parseCodesCsv($tmpPath);

            $existingSet = array_fill_keys($repository->existingCodes($parsed['codes']), true);

            $newCount = 0;
            $sample = [];

            foreach ($parsed['codes'] as $index => $code) {
                $isNew = !isset($existingSet[$code]);

                if ($isNew) {
                    $newCount++;
                }

                if ($index < 12) {
                    $sample[] = ['code' => (string) $code, 'is_new' => $isNew];
                }
            }

            $_SESSION['import_codes_preview'] = [
                'ts' => time(),
                'file_name' => (string) ($_FILES['codes']['name'] ?? 'codigos.csv'),
                'codes' => $parsed['codes'],
                'summary' => [
                    'new' => $newCount,
                    'existing' => count($parsed['codes']) - $newCount,
                    'duplicates' => $parsed['duplicates'],
                    'rejected' => count($parsed['rejected']),
                    'lines' => $parsed['lines'],
                ],
                'sample' => $sample,
                'rejected' => $parsed['rejected'],
            ];
        } catch (ImportException $exception) {
            flash_set('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('Falha ao pré-visualizar códigos: ' . $exception->getMessage());
            flash_set('error', 'Não foi possível ler o arquivo. Tente novamente mais tarde.');
        }

        redirect_to('admin.php');
    }

    if ($action === 'confirm_codes') {
        $preview = $_SESSION['import_codes_preview'] ?? null;
        unset($_SESSION['import_codes_preview']);

        if (!is_array($preview) || empty($preview['codes']) || (time() - (int) ($preview['ts'] ?? 0)) > IMPORT_PREVIEW_TTL) {
            flash_set('error', 'A pré-visualização expirou. Envie o arquivo novamente.');
            redirect_to('admin.php');
        }

        try {
            $repository = admin_repository($config);
            $newCodes = $repository->insertNewCodes($preview['codes'], 'Inativa');
            $inserted = count($newCodes);
            $existing = count($preview['codes']) - $inserted;

            // Atividades novas (inativas): criar no serviço LTI e depois desativar (em lotes).
            $failedIds = [];

            if ($newCodes !== []) {
                $failedIds = admin_notifier($config)->registerAndApply($newCodes, false);
            }

            $rejected = is_array($preview['rejected'] ?? null) ? $preview['rejected'] : [];

            if ($rejected !== []) {
                $_SESSION['last_rejected'] = $rejected;
            }

            if ($failedIds !== []) {
                $_SESSION["last_failed_ids"] = ["ids" => $failedIds, "enable" => false];
            }

            $message = sprintf('%d código(s) cadastrado(s) como Inativa; %d já cadastrado(s).', $inserted, $existing);

            if ($rejected !== []) {
                $message .= sprintf(' %d linha(s) não importada(s) por CRT inválido.', count($rejected));
            }

            if ($failedIds !== []) {
                flash_set('error', $message . sprintf(
                    ' Atenção: %d de %d não puderam ser registrados/desativados no serviço de correção automática (veja a lista abaixo).',
                    count($failedIds),
                    count($newCodes)
                ));
            } else {
                flash_set('success', $message);
            }
        } catch (Throwable $exception) {
            error_log('Falha ao cadastrar códigos inativos: ' . $exception->getMessage());
            flash_set('error', 'Não foi possível concluir o cadastro. Tente novamente mais tarde.');
        }

        redirect_to('admin.php');
    }
}

if (!$isAdmin) {
    http_response_code(403);
}

$flash = flash_get();

function admin_pending_preview(string $key): ?array
{
    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        return null;
    }

    if ((time() - (int) ($_SESSION[$key]['ts'] ?? 0)) <= IMPORT_PREVIEW_TTL) {
        return $_SESSION[$key];
    }

    unset($_SESSION[$key]);

    return null;
}

/**
 * Renderiza a tabela das linhas não importadas (CRT inválido).
 *
 * @param list<array{line:int, crt:string, reason:string}> $rejected
 */
function render_rejected_table(array $rejected): void
{
    if ($rejected === []) {
        return;
    }
    ?>
    <div class="table-wrap" style="max-height:300px;overflow-y:auto">
        <table>
            <thead>
            <tr><th>Linha</th><th>CRT</th><th>Motivo</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rejected as $r): ?>
                <tr>
                    <td><?= e($r['line'] ?? '') ?></td>
                    <td><code><?= e(($r['crt'] ?? '') !== '' ? $r['crt'] : '(vazio)') ?></code></td>
                    <td><?= e($r['reason'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

$preview = $isAdmin ? admin_pending_preview('import_preview') : null;
$codesPreview = $isAdmin ? admin_pending_preview('import_codes_preview') : null;

$lastRejected = null;
if ($isAdmin && isset($_SESSION['last_rejected']) && is_array($_SESSION['last_rejected'])) {
    $lastRejected = $_SESSION['last_rejected'];
    unset($_SESSION['last_rejected']);
}

$lastFailedIds = null;
if ($isAdmin && isset($_SESSION['last_failed_ids']) && is_array($_SESSION['last_failed_ids'])) {
    $lastFailedIds = $_SESSION['last_failed_ids'];
    unset($_SESSION['last_failed_ids']);
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

        <?php if ($lastFailedIds !== null): ?>
            <?php $failedList = $lastFailedIds['ids']; ?>
            <section class="table-card" style="padding:24px">
                <h3 style="margin-top:0"><?= e(count($failedList)) ?> ID(s) com erro no serviço de correção automática</h3>
                <p class="login-copy">
                    Estes IDs <strong>foram gravados no banco</strong>, mas o lote deles falhou ao ser enviado ao serviço externo
                    (<?= $lastFailedIds['enable'] ? 'registrar e ativar' : 'registrar e desativar' ?>).
                    Reenviar o arquivo não repete o envio (eles já existem no banco) — use o botão abaixo para tentar novamente.
                </p>
                <textarea class="input" rows="6" readonly style="width:100%;font-family:monospace"><?= e(implode("\n", $failedList)) ?></textarea>
                <form method="post" style="margin-top:12px">
                    <input type="hidden" name="action" value="retry_failed">
                    <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="ids" value="<?= e(implode(',', $failedList)) ?>">
                    <input type="hidden" name="enable" value="<?= $lastFailedIds['enable'] ? '1' : '0' ?>">
                    <button class="btn btn-primary" type="submit">Tentar enviar novamente</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($lastRejected !== null): ?>
            <section class="table-card" style="padding:24px">
                <h3 style="margin-top:0"><?= e(count($lastRejected)) ?> linha(s) NÃO importada(s) na última importação</h3>
                <p class="login-copy">O CRT deve ser um texto <strong>sem espaços</strong>. Corrija estas linhas e importe novamente.</p>
                <?php render_rejected_table($lastRejected); ?>
            </section>
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
                    <div><strong style="font-size:24px"><?= e($s['rejected']) ?></strong><br>linha(s) <strong>não importadas</strong> (CRT vazio ou com espaço)</div>
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

                <?php if (($preview['rejected'] ?? []) !== []): ?>
                    <h3>Linhas que NÃO serão importadas (<?= e(count($preview['rejected'])) ?>)</h3>
                    <p class="login-copy">CRT com espaço ou vazio. Corrija na planilha para incluí-las.</p>
                    <?php render_rejected_table($preview['rejected']); ?>
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
        <?php elseif ($codesPreview !== null): ?>
            <?php $cs = $codesPreview['summary']; ?>
            <section class="table-card" style="padding:28px">
                <h2 style="margin-top:0">Confira antes de cadastrar (códigos inativos)</h2>
                <p class="login-copy">Arquivo: <strong><?= e($codesPreview['file_name']) ?></strong> · <?= e($cs['lines']) ?> código(s) no arquivo. Nada foi gravado ainda.</p>

                <div class="import-summary" style="display:flex;gap:24px;flex-wrap:wrap;margin:18px 0">
                    <div><strong style="font-size:24px"><?= e($cs['new']) ?></strong><br>código(s) <strong>novos</strong> (serão cadastrados como <strong>Inativa</strong>)</div>
                    <div><strong style="font-size:24px"><?= e($cs['existing']) ?></strong><br>já cadastrados (não serão adicionados)</div>
                    <div><strong style="font-size:24px"><?= e($cs['duplicates']) ?></strong><br>repetidos no próprio arquivo (ignorados)</div>
                    <div><strong style="font-size:24px"><?= e($cs['rejected']) ?></strong><br>código(s) <strong>não importados</strong> (com espaço)</div>
                </div>

                <p class="login-copy">Os códigos novos entram apenas com <strong>código e status Inativa</strong> — sem nome, bloco ou ano (aparecem como &ldquo;—&rdquo; na listagem).</p>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>CRT<br><small>→ codigo_disciplina</small></th>
                            <th>Situação</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($codesPreview['sample'] as $row): ?>
                            <tr>
                                <td><code><?= e($row['code']) ?></code></td>
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
                <?php if ($cs['lines'] > count($codesPreview['sample'])): ?>
                    <p class="login-copy"><small>Mostrando os primeiros <?= e(count($codesPreview['sample'])) ?> de <?= e($cs['lines']) ?>.</small></p>
                <?php endif; ?>

                <?php if (($codesPreview['rejected'] ?? []) !== []): ?>
                    <h3>Códigos que NÃO serão cadastrados (<?= e(count($codesPreview['rejected'])) ?>)</h3>
                    <p class="login-copy">CRT com espaço. Corrija a lista para incluí-los.</p>
                    <?php render_rejected_table($codesPreview['rejected']); ?>
                <?php endif; ?>

                <div class="filter-actions" style="margin-top:20px;display:flex;gap:10px">
                    <form method="post">
                        <input type="hidden" name="action" value="confirm_codes">
                        <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                        <button class="btn btn-primary" type="submit"<?= $cs['new'] === 0 ? ' disabled' : '' ?>>Cadastrar <?= e($cs['new']) ?> como Inativa</button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="action" value="cancel_codes">
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
                <p class="login-copy">A primeira linha deve ser exatamente o cabeçalho:</p>
                <p><code>CRT;DISCIPLINA;BLOCO;ANO</code></p>
                <p class="login-copy">
                    Onde <strong>CRT</strong> é o nome/código da oferta (usado como identificador único),
                    <strong>DISCIPLINA</strong> o nome, <strong>BLOCO</strong> o bloco e <strong>ANO</strong> o ano.
                    Novas disciplinas entram como <strong>Ativa</strong>.
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

            <section class="table-card" style="padding:28px">
                <h2 style="margin-top:0">Cadastrar códigos como Inativa</h2>
                <p class="login-copy">
                    Envie um arquivo com <strong>apenas a lista de CRT</strong> (um código por linha; também aceita separados por <code>;</code> ou <code>,</code>). Um cabeçalho <code>CRT</code> na primeira linha é opcional.
                </p>
                <p class="login-copy">
                    Os códigos ainda não cadastrados são adicionados com status <strong>Inativa</strong>, gravando apenas o código — <strong>sem nome, bloco ou ano</strong>. Você verá uma pré-visualização antes de gravar.
                </p>

                <form method="post" enctype="multipart/form-data" class="filters-form" style="margin-top:20px">
                    <input type="hidden" name="action" value="preview_codes">
                    <input type="hidden" name="_csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?= e($config['upload']['max_bytes']) ?>">
                    <div class="field search-field">
                        <label for="codes">Lista de CRT (CSV)</label>
                        <input class="input" id="codes" name="codes" type="file" accept=".csv,text/csv,text/plain" required>
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
