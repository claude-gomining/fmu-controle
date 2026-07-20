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
