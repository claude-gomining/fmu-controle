<?php

declare(strict_types=1);

/**
 * Teste de integração do ActivityControlNotifier contra um serviço LTI
 * simulado (tests/mock-lti-server.php) no servidor embutido do PHP.
 * Execução: php tests/notifier-test.php
 */

require_once __DIR__ . '/../src/ActivityControlNotifier.php';

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

$port = 8199;
$logFile = sys_get_temp_dir() . '/mock-lti-' . bin2hex(random_bytes(4)) . '.log';
touch($logFile);

$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/mock-lti-server.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    null,
    ['MOCK_LOG' => $logFile] + getenv()
);

if (!is_resource($server)) {
    fwrite(STDERR, "Não foi possível iniciar o servidor de teste.\n");
    exit(1);
}

// Aguarda o servidor subir.
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

function last_request(string $logFile): ?array
{
    $lines = array_filter(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    $last = end($lines);

    return $last === false ? null : json_decode($last, true);
}

$notifier = new ActivityControlNotifier("http://127.0.0.1:{$port}", 'fmu', 5);

echo "notifier: create (POST /v1/control/list)\n";
check($notifier->notifyCreated(['FMU-0001', 'FMU-0002']) === true, 'create com sucesso retorna true');
$request = last_request($logFile);
check($request !== null && $request['method'] === 'POST', 'create usa método POST');
check($request !== null && $request['uri'] === '/v1/control/list', 'URL do create é /v1/control/list (sem enable/disable)');
$payload = $request !== null ? json_decode($request['body'], true) : null;
check($payload === ['activityId' => 'FMU-0001,FMU-0002', 'institution' => 'fmu'], 'payload do create com activityId e institution');

echo "notifier: enable\n";
check($notifier->notifyEnabled(['FMU-0001', 'FMU-0002', 'FMU-0003']) === true, 'enable com sucesso retorna true');
$request = last_request($logFile);
check($request !== null && $request['method'] === 'PUT', 'requisição usa método PUT');
check($request !== null && $request['uri'] === '/v1/control/enable/list', 'URL do enable é /v1/control/enable/list');
check($request !== null && str_starts_with($request['content_type'], 'application/json'), 'Content-Type é application/json');
$payload = $request !== null ? json_decode($request['body'], true) : null;
check($payload === ['activityId' => 'FMU-0001,FMU-0002,FMU-0003', 'institution' => 'fmu'], 'payload tem activityId separado por vírgula e institution fmu');

echo "notifier: disable\n";
check($notifier->notifyDisabled(['FMU-0009']) === true, 'disable com sucesso retorna true');
$request = last_request($logFile);
check($request !== null && $request['uri'] === '/v1/control/disable/list', 'URL do disable é /v1/control/disable/list');
$payload = $request !== null ? json_decode($request['body'], true) : null;
check($payload === ['activityId' => 'FMU-0009', 'institution' => 'fmu'], 'payload do disable correto');

echo "notifier: saneamento dos códigos\n";
check($notifier->notifyEnabled([' FMU-0001 ', '', 'FMU-0001', 'FMU-0002', ['array-ignorado']]) === true, 'códigos duplicados/vazios/não-escalares são saneados');
$request = last_request($logFile);
$payload = $request !== null ? json_decode($request['body'], true) : null;
check($payload !== null && $payload['activityId'] === 'FMU-0001,FMU-0002', 'payload saneado mantém apenas códigos válidos e únicos');

echo "notifier: envio em lotes\n";
file_put_contents($logFile, '');
$batched = new ActivityControlNotifier("http://127.0.0.1:{$port}", 'fmu', 5, 100);
$many = [];
for ($i = 1; $i <= 250; $i++) {
    $many[] = 'FMU-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
}
check($batched->notifyCreated($many) === true, '250 códigos enviados com sucesso');
$requests = array_filter(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
check(count($requests) === 3, '250 códigos geram 3 requisições (100+100+50)');
$sizes = array_map(static function (string $line): int {
    $r = json_decode($line, true);
    $p = json_decode($r['body'], true);
    return count(explode(',', $p['activityId']));
}, array_values($requests));
check($sizes === [100, 100, 50], 'lotes têm exatamente 100, 100 e 50 IDs');
check($batched->lastFailedCodes() === [], 'nenhum ID falhou');

echo "notifier: IDs do lote que falhou são reportados\n";
file_put_contents($logFile, '');
// O mock responde 500 quando o payload contém FAIL: colocamos no 2º lote (índice 120).
$mixed = [];
for ($i = 1; $i <= 150; $i++) {
    $mixed[] = ($i === 120) ? 'FAIL' : 'ID-' . $i;
}
check($batched->notifyCreated($mixed) === false, 'retorna false quando um lote falha');
$failed = $batched->lastFailedCodes();
check(count($failed) === 50, 'apenas os 50 IDs do lote com falha são reportados');
check(in_array('FAIL', $failed, true) && in_array('ID-101', $failed, true), 'IDs do lote com falha estão na lista');
check(!in_array('ID-1', $failed, true), 'IDs do lote bem-sucedido NÃO entram na lista');
check(count(array_filter(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])) === 2, 'os dois lotes foram enviados mesmo com falha no segundo');

echo "notifier: registerAndApply (criar -> ativar)\n";
file_put_contents($logFile, '');
$failed = $batched->registerAndApply(['A-1', 'A-2'], true);
check($failed === [], 'sem falhas retorna lista vazia');
$reqs = array_values(array_filter(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []));
check(count($reqs) === 2, 'faz duas chamadas (create + enable)');
$first = json_decode($reqs[0], true);
$second = json_decode($reqs[1], true);
check($first['uri'] === '/v1/control/list' && $first['method'] === 'POST', 'primeiro POST /v1/control/list');
check($second['uri'] === '/v1/control/enable/list' && $second['method'] === 'PUT', 'depois PUT enable/list');

file_put_contents($logFile, '');
$failed = $batched->registerAndApply(['FAIL', 'B-2'], false);
check($failed === ['FAIL', 'B-2'], 'falha no create reporta os IDs e não tenta desativar');
check(count(array_filter(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])) === 1, 'não envia o disable quando o create falhou');

echo "notifier: casos de falha\n";
$before = count(file($logFile) ?: []);
check($notifier->notifyEnabled([]) === true, 'lista vazia retorna true sem enviar requisição');
check(count(file($logFile) ?: []) === $before, 'nenhuma requisição foi enviada para lista vazia');
check($notifier->notifyEnabled(['FAIL']) === false, 'resposta HTTP 500 retorna false');
$offline = new ActivityControlNotifier('http://127.0.0.1:1', 'fmu', 1);
check($offline->notifyEnabled(['FMU-0001']) === false, 'serviço inacessível retorna false (sem exceção)');

proc_terminate($server);
proc_close($server);
@unlink($logFile);

echo "\n{$assertions} asserções, {$failures} falha(s)\n";
exit($failures === 0 ? 0 : 1);
