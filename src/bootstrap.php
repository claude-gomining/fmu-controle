<?php

declare(strict_types=1);

/**
 * Inicialização comum às páginas do portal: carrega as dependências, aplica o
 * endurecimento de sessão e os headers de segurança e devolve [$config, $auth].
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/MongoConnection.php';
require_once __DIR__ . '/DisciplineRepository.php';
require_once __DIR__ . '/UserRepository.php';
require_once __DIR__ . '/LoginRateLimiter.php';
require_once __DIR__ . '/ActivityControlNotifier.php';
require_once __DIR__ . '/ImportException.php';
require_once __DIR__ . '/ActivityImporter.php';

$config = require __DIR__ . '/../config/config.php';

// Endurecimento de erros: em produção não exibe detalhes/paths ao usuário;
// registra no log do servidor. Defina APP_DEBUG=1 para depurar localmente.
if (env_value('APP_DEBUG') === '1') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
ini_set('log_errors', '1');

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

return [$config, new Auth()];
