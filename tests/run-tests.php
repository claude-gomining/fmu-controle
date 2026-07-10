<?php

declare(strict_types=1);

/**
 * Testes unitários sem dependências externas (não exigem MongoDB nem extensão mongodb).
 * Execução: php tests/run-tests.php
 */

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/LoginRateLimiter.php';
require_once __DIR__ . '/../src/MongoConnection.php';
require_once __DIR__ . '/../src/UserRepository.php';

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

$_SESSION = [];
$_GET = [];

echo "helpers: escape de HTML\n";
check(e('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;', 'escapa tags HTML');
check(e('"aspas" \'simples\'') === '&quot;aspas&quot; &#039;simples&#039;', 'escapa aspas duplas e simples');
check(e(123) === '123', 'aceita valores não-string');

echo "helpers: CSRF\n";
$token = csrf_token();
check(strlen($token) === 64 && ctype_xdigit($token), 'token tem 64 caracteres hexadecimais');
check(csrf_token() === $token, 'token é estável dentro da sessão');
check(verify_csrf($token) === true, 'token correto é aceito');
check(verify_csrf('errado') === false, 'token incorreto é rejeitado');
check(verify_csrf(null) === false, 'token ausente é rejeitado');

echo "helpers: flash\n";
flash_set('error', 'mensagem');
$flash = flash_get();
check($flash === ['type' => 'error', 'message' => 'mensagem'], 'flash é gravada e lida');
check(flash_get() === null, 'flash é consumida após leitura');

echo "helpers: current_url\n";
$_GET = ['q' => 'a b', 'page' => '2', 'vazio' => ''];
check(current_url() === 'index.php?q=a+b&page=2', 'monta query string e remove vazios');
check(current_url(['page' => 3]) === 'index.php?q=a+b&page=3', 'override de parâmetro');
$_GET = [];
check(current_url() === 'index.php', 'sem parâmetros retorna index.php');

echo "helpers: is_https / client_ip\n";
unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
check(is_https() === false, 'sem indicadores retorna false');
$_SERVER['HTTPS'] = 'on';
check(is_https() === true, 'HTTPS=on retorna true');
$_SERVER['HTTPS'] = 'off';
check(is_https() === false, 'HTTPS=off retorna false');
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
check(is_https() === true, 'X-Forwarded-Proto https retorna true');
unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
$_SERVER['REMOTE_ADDR'] = '10.0.0.9';
check(client_ip() === '10.0.0.9', 'client_ip usa REMOTE_ADDR');

echo "LoginRateLimiter\n";
$dir = sys_get_temp_dir() . '/fmu-tests-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$limiter = new LoginRateLimiter($dir, 3, 900);
$key = 'user|admin|10.0.0.9';

check($limiter->isBlocked($key) === false, 'chave nova não está bloqueada');
$limiter->registerFailure($key);
$limiter->registerFailure($key);
check($limiter->isBlocked($key) === false, '2 falhas (limite 3) não bloqueiam');
$limiter->registerFailure($key);
check($limiter->isBlocked($key) === true, '3 falhas bloqueiam');
check($limiter->retryAfterSeconds($key) > 0, 'retryAfterSeconds informa espera');
check($limiter->isBlocked('outra-chave') === false, 'bloqueio não afeta outras chaves');
$limiter->clear($key);
check($limiter->isBlocked($key) === false, 'clear desbloqueia (login bem-sucedido)');

// Expiração da janela: grava estado antigo diretamente no arquivo.
$staleFile = $dir . '/fmu_login_throttle_' . hash('sha256', $key) . '.json';
file_put_contents($staleFile, json_encode(['attempts' => 99, 'window_start' => time() - 901]));
check($limiter->isBlocked($key) === false, 'bloqueio expira após a janela de lockout');
file_put_contents($staleFile, '{corrompido');
check($limiter->isBlocked($key) === false, 'arquivo corrompido não causa erro nem bloqueio');
array_map('unlink', glob($dir . '/*') ?: []);
rmdir($dir);

echo "UserRepository: aceitação de hashes\n";
$repo = (new ReflectionClass(UserRepository::class))->newInstanceWithoutConstructor();
$isSupportedHash = fn (string $hash): bool => (new ReflectionMethod(UserRepository::class, 'isSupportedHash'))->invoke($repo, $hash);

check($isSupportedHash(password_hash('teste', PASSWORD_BCRYPT)) === true, 'hash bcrypt é aceito');
check($isSupportedHash(password_hash('teste', PASSWORD_DEFAULT)) === true, 'hash PASSWORD_DEFAULT é aceito');
check($isSupportedHash('admin123') === false, 'senha em texto puro é rejeitada');
check($isSupportedHash(md5('admin123')) === false, 'hash md5 simples é rejeitado');
check($isSupportedHash('') === false, 'hash vazio é rejeitado');
check(method_exists($repo, 'passwordMatches') === false, 'fallback de comparação em texto puro foi removido');

$dummyHash = (new ReflectionClassConstant(UserRepository::class, 'DUMMY_HASH'))->getValue();
check($isSupportedHash($dummyHash) === true, 'DUMMY_HASH é um hash bcrypt válido');
check(password_verify('qualquer-senha', $dummyHash) === false, 'DUMMY_HASH não valida nenhuma senha');

echo "UserRepository: isActive falha fechado\n";
$isActive = fn (object $user): bool => (new ReflectionMethod(UserRepository::class, 'isActive'))->invoke($repo, $user);

check($isActive((object) []) === false, 'usuário sem campo ativo/active é bloqueado (fail-closed)');
check($isActive((object) ['ativo' => true]) === true, 'ativo=true permite');
check($isActive((object) ['ativo' => false]) === false, 'ativo=false bloqueia');
check($isActive((object) ['ativo' => 1]) === true, 'ativo=1 permite');
check($isActive((object) ['ativo' => 0]) === false, 'ativo=0 bloqueia');
check($isActive((object) ['ativo' => 'sim']) === true, 'ativo="sim" permite');
check($isActive((object) ['ativo' => 'nao']) === false, 'ativo="nao" bloqueia');
check($isActive((object) ['active' => true]) === true, 'campo legado active=true permite');

echo "config: seção de segurança\n";
$config = require __DIR__ . '/../config/config.php';
check(isset($config['security']['login_max_attempts']) && $config['security']['login_max_attempts'] >= 1, 'login_max_attempts definido');
check(isset($config['security']['login_lockout_seconds']) && $config['security']['login_lockout_seconds'] >= 60, 'login_lockout_seconds definido');
check(is_string($config['security']['throttle_dir']) && $config['security']['throttle_dir'] !== '', 'throttle_dir definido');

echo "\n{$assertions} asserções, {$failures} falha(s)\n";
exit($failures === 0 ? 0 : 1);
