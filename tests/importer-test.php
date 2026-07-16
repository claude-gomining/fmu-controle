<?php

declare(strict_types=1);

/**
 * Testes do ActivityImporter e dos papéis de acesso (Auth::isAdmin).
 * Não exigem MongoDB nem a extensão mongodb.
 * Execução: php tests/importer-test.php
 */

require_once __DIR__ . '/../src/ImportException.php';
require_once __DIR__ . '/../src/ActivityImporter.php';
require_once __DIR__ . '/../src/Auth.php';

$failures = 0;
$assertions = 0;

function check(bool $condition, string $description): void
{
    global $failures, $assertions;
    $assertions++;

    if ($condition) {
        echo "  ok - {$description}\n";
    } else {
        $failures++;
        echo "  FALHOU - {$description}\n";
    }
}

$tempFiles = [];

function csv_file(string $content): string
{
    global $tempFiles;
    $path = tempnam(sys_get_temp_dir(), 'fmu_csv_');
    file_put_contents($path, $content);
    $tempFiles[] = $path;

    return $path;
}

$importer = new ActivityImporter();

echo "importer: planilha válida\n";
$result = $importer->parseCsv(csv_file(
    "CRT;DISCIPLINA;BLOCO;ANO\n"
    . "FMU-0001;Matematica Aplicada;A;2026\n"
    . "FMU-0002;Logica de Programacao;B;2026\n"
));
check(count($result['activities']) === 2, 'duas disciplinas lidas');
check($result['activities'][0] === [
    'codigo_disciplina' => 'FMU-0001',
    'nome_disciplina' => 'Matematica Aplicada',
    'bloco' => 'A',
    'ano' => 2026,
], 'CRT vira codigo_disciplina e ANO numérico vira int');
check($result['invalid'] === 0 && $result['duplicates'] === 0, 'sem inválidas nem duplicadas');

echo "importer: trim das células\n";
$result = $importer->parseCsv(csv_file(
    "CRT;DISCIPLINA;BLOCO;ANO\n"
    . "  FMU-0003 ;  Banco de Dados  ;  C ;  2027 \n"
));
check($result['activities'][0] === [
    'codigo_disciplina' => 'FMU-0003',
    'nome_disciplina' => 'Banco de Dados',
    'bloco' => 'C',
    'ano' => 2027,
], 'espaços em branco são removidos de cada célula');

echo "importer: cabeçalho\n";
$ok = false;
try {
    $importer->parseCsv(csv_file("NOME;DISCIPLINA;BLOCO;ANO\nx;y;z;2026\n"));
} catch (ImportException $e) {
    $ok = str_contains($e->getMessage(), 'Cabeçalho inválido');
}
check($ok, 'cabeçalho errado é rejeitado com ImportException');

$result = $importer->parseCsv(csv_file(" crt ; disciplina ; bloco ; ano \nFMU-1;Nome;A;2026\n"));
check(count($result['activities']) === 1, 'cabeçalho é tolerante a caixa e espaços (crt/Disciplina...)');

echo "importer: BOM UTF-8\n";
$result = $importer->parseCsv(csv_file("\xEF\xBB\xBFCRT;DISCIPLINA;BLOCO;ANO\nFMU-9;Nome;A;2026\n"));
check(count($result['activities']) === 1 && $result['activities'][0]['codigo_disciplina'] === 'FMU-9', 'BOM no início não quebra o cabeçalho');

echo "importer: encoding Windows-1252\n";
$latin1 = mb_convert_encoding("CRT;DISCIPLINA;BLOCO;ANO\nFMU-8;Comunicação;A;2026\n", 'Windows-1252', 'UTF-8');
$result = $importer->parseCsv(csv_file($latin1));
check($result['activities'][0]['nome_disciplina'] === 'Comunicação', 'acentos de arquivo Windows-1252 são convertidos para UTF-8');

echo "importer: linhas em branco, sem CRT e duplicadas\n";
$result = $importer->parseCsv(csv_file(
    "CRT;DISCIPLINA;BLOCO;ANO\n"
    . "FMU-0001;Primeira;A;2026\n"
    . "\n"                              // linha em branco -> ignorada
    . ";Sem codigo;B;2026\n"           // sem CRT -> inválida
    . "FMU-0001;Repetida;A;2026\n"     // CRT repetido -> duplicada
    . "   ;   ;   ;   \n"              // só espaços -> ignorada
    . "FMU-0002;Segunda;B;2026\n"
));
check(count($result['activities']) === 2, 'apenas 2 disciplinas únicas e válidas');
check($result['invalid'] === 1, '1 linha sem CRT contada como inválida');
check($result['duplicates'] === 1, '1 CRT repetido contado como duplicado');
$codigos = array_column($result['activities'], 'codigo_disciplina');
check($codigos === ['FMU-0001', 'FMU-0002'], 'mantém a primeira ocorrência do CRT repetido');

echo "importer: ANO não numérico e arquivo vazio\n";
$result = $importer->parseCsv(csv_file("CRT;DISCIPLINA;BLOCO;ANO\nFMU-7;Nome;A;2026/1\n"));
check($result['activities'][0]['ano'] === '2026/1', 'ANO não numérico é mantido como string (com trim)');

$ok = false;
try {
    $importer->parseCsv(csv_file("   \n"));
} catch (ImportException $e) {
    $ok = str_contains($e->getMessage(), 'vazio');
}
check($ok, 'arquivo vazio lança ImportException');

echo "importer: limite de linhas\n";
$small = new ActivityImporter(2);
$ok = false;
try {
    $small->parseCsv(csv_file("CRT;DISCIPLINA;BLOCO;ANO\nA;1;x;2026\nB;2;x;2026\nC;3;x;2026\n"));
} catch (ImportException $e) {
    $ok = str_contains($e->getMessage(), 'limite');
}
check($ok, 'excede o limite de linhas configurado');

echo "Auth: controle de acesso admin\n";
$_SESSION = ['user' => 'Gomining', 'username' => 'gomining'];
$auth = new Auth();
check($auth->username() === 'gomining', 'username retorna o login');
check($auth->user() === 'Gomining', 'user retorna o nome de exibição');
check($auth->isAdmin(['gomining']) === true, 'gomining é admin');
check($auth->isAdmin(['fmu']) === false, 'gomining não é admin quando a lista não o inclui');

$_SESSION = ['user' => 'Gomining Brasil', 'username' => 'GoMining'];
check((new Auth())->isAdmin(['gomining']) === true, 'comparação de admin é sem distinção de maiúsculas');

$_SESSION = ['user' => 'FMU', 'username' => 'fmu'];
check((new Auth())->isAdmin(['gomining']) === false, 'usuário fmu não é admin');

$_SESSION = ['user' => 'Legado'];
check((new Auth())->username() === null, 'sessão antiga sem username retorna null');
check((new Auth())->isAdmin(['gomining']) === false, 'sessão sem username não é admin');

$_SESSION = [];
check((new Auth())->isAdmin(['gomining']) === false, 'sessão sem login não é admin');

echo "Auth: painéis por login (fmu / afya / gomining)\n";
$panelMap = [
    'fmu' => ['fmu'],
    'afya' => ['afya'],
    'gomining' => ['fmu', 'afya'],
];

$_SESSION = ['user' => 'FMU', 'username' => 'fmu'];
$fmu = new Auth();
check($fmu->canAccess('fmu', $panelMap) === true, 'fmu acessa o painel da FMU');
check($fmu->canAccess('afya', $panelMap) === false, 'fmu NÃO acessa o painel da Afya');
check($fmu->panels($panelMap) === ['fmu'], 'fmu tem apenas o painel fmu');

$_SESSION = ['user' => 'Afya', 'username' => 'afya'];
$afya = new Auth();
check($afya->canAccess('afya', $panelMap) === true, 'afya acessa o painel da Afya');
check($afya->canAccess('fmu', $panelMap) === false, 'afya NÃO acessa o painel da FMU');

$_SESSION = ['user' => 'Gomining', 'username' => 'gomining'];
$gomining = new Auth();
check($gomining->canAccess('fmu', $panelMap) === true, 'gomining acessa o painel da FMU');
check($gomining->canAccess('afya', $panelMap) === true, 'gomining acessa o painel da Afya');
check($gomining->panels($panelMap) === ['fmu', 'afya'], 'gomining tem os dois painéis');

$_SESSION = ['user' => 'Gomining', 'username' => 'GoMining'];
check((new Auth())->canAccess('afya', $panelMap) === true, 'lookup de painel é sem distinção de maiúsculas');

$_SESSION = ['user' => 'Desconhecido', 'username' => 'zzz'];
check((new Auth())->panels($panelMap) === [], 'login fora do mapa não tem painel algum');

$_SESSION = [];
check((new Auth())->canAccess('fmu', $panelMap) === false, 'sessão sem login não acessa painel');

foreach ($tempFiles as $path) {
    @unlink($path);
}

echo "\n{$assertions} asserções, {$failures} falha(s)\n";
exit($failures === 0 ? 0 : 1);
