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
