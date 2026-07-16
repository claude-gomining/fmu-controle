<?php

declare(strict_types=1);

/**
 * Cadastra (ou atualiza) os usuários de acesso ao portal: FMU e Gomining.
 *
 * As senhas nunca ficam no código: informe-as de forma interativa quando o
 * script pedir, ou via variáveis de ambiente FMU_USER_PASSWORD,
 * GOMINING_USER_PASSWORD e AFYA_USER_PASSWORD (útil para automação).
 *
 * Execução (Linux/macOS):
 *   php scripts/add-users.php
 *
 * Execução (Windows, com a extensão do repositório):
 *   php -d extension=.\vendor\php-ext\mongodb\php_mongodb.dll scripts\add-users.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado pela linha de comando.\n");
    exit(1);
}

require_once __DIR__ . '/../src/MongoConnection.php';

const MIN_PASSWORD_LENGTH = 8;

$usersToCreate = [
    [
        'usuario' => 'fmu',
        'nome' => 'FMU',
        'env' => 'FMU_USER_PASSWORD',
    ],
    [
        'usuario' => 'gomining',
        'nome' => 'Gomining',
        'env' => 'GOMINING_USER_PASSWORD',
    ],
    [
        'usuario' => 'afya',
        'nome' => 'Afya',
        'env' => 'AFYA_USER_PASSWORD',
    ],
];

function read_hidden_line(string $prompt): string
{
    echo $prompt;

    $hidden = false;

    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        $hidden = shell_exec('stty -echo 2> /dev/null') !== null;
    }

    if (!$hidden) {
        echo "(atenção: a senha ficará visível ao digitar)\n> ";
    }

    $line = fgets(STDIN);

    if ($hidden) {
        shell_exec('stty echo 2> /dev/null');
        echo "\n";
    }

    if ($line === false) {
        fwrite(STDERR, "Entrada encerrada. Abortando.\n");
        exit(1);
    }

    return rtrim($line, "\r\n");
}

function collect_password(string $usuario, string $envVar): string
{
    $fromEnv = getenv($envVar);

    if (is_string($fromEnv) && $fromEnv !== '') {
        if (strlen($fromEnv) < MIN_PASSWORD_LENGTH) {
            fwrite(STDERR, "A senha em {$envVar} precisa ter pelo menos " . MIN_PASSWORD_LENGTH . " caracteres.\n");
            exit(1);
        }

        echo "Usuário '{$usuario}': senha lida da variável de ambiente {$envVar}.\n";

        return $fromEnv;
    }

    while (true) {
        $password = read_hidden_line("Senha para o usuário '{$usuario}' (mínimo " . MIN_PASSWORD_LENGTH . " caracteres): ");

        if (strlen($password) < MIN_PASSWORD_LENGTH) {
            echo "Senha muito curta. Tente novamente.\n";
            continue;
        }

        $confirmation = read_hidden_line("Confirme a senha do usuário '{$usuario}': ");

        if (!hash_equals($password, $confirmation)) {
            echo "As senhas não conferem. Tente novamente.\n";
            continue;
        }

        return $password;
    }
}

// Coleta as senhas antes de tocar no banco, para falhar cedo em caso de erro de digitação.
foreach ($usersToCreate as $index => $user) {
    $usersToCreate[$index]['senha'] = collect_password($user['usuario'], $user['env']);
}

$config = require __DIR__ . '/../config/config.php';

try {
    $connection = new MongoConnection(
        $config['mongo']['uri'],
        $config['mongo']['database'],
        $config['mongo']['user_collection']
    );

    $now = new MongoDB\BSON\UTCDateTime((int) (microtime(true) * 1000));
    $bulk = new MongoDB\Driver\BulkWrite();

    foreach ($usersToCreate as $user) {
        $bulk->update(
            ['usuario' => $user['usuario']],
            ['$set' => [
                'usuario' => $user['usuario'],
                'nome' => $user['nome'],
                'senha_hash' => password_hash($user['senha'], PASSWORD_DEFAULT),
                'ativo' => true,
                'data' => $now,
            ]],
            ['upsert' => true]
        );
    }

    $result = $connection->manager()->executeBulkWrite($connection->namespace(), $bulk);
} catch (Throwable $exception) {
    fwrite(STDERR, "Não foi possível gravar os usuários no MongoDB.\n");
    fwrite(STDERR, "Verifique a extensão PHP mongodb e a variável MONGODB_URI.\n");
    fwrite(STDERR, "Detalhe: " . $exception->getMessage() . "\n");
    exit(1);
}

$total = $result->getInsertedCount() + $result->getModifiedCount() + $result->getUpsertedCount();

echo "Usuários inseridos/atualizados: {$total}\n";

foreach ($usersToCreate as $user) {
    echo "  - {$user['usuario']} ({$user['nome']})\n";
}

echo "Pronto. Os usuários já podem acessar o portal.\n";
