<?php

declare(strict_types=1);

/**
 * Router para o servidor embutido do PHP que simula o serviço LTI nos testes.
 * Registra cada requisição recebida em MOCK_LOG e responde 200, ou 500 quando
 * o payload contém a palavra FAIL (para testar o tratamento de erro).
 */

$log = getenv('MOCK_LOG') ?: sys_get_temp_dir() . '/mock-lti.log';
$body = (string) file_get_contents('php://input');

file_put_contents($log, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'content_type' => $_SERVER['HTTP_CONTENT_TYPE'] ?? '',
    'body' => $body,
]) . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');

if (str_contains($body, 'FAIL')) {
    http_response_code(500);
    echo '{"ok":false}';
    exit;
}

http_response_code(200);
echo '{"ok":true}';
