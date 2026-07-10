<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/MongoConnection.php';

$config = require __DIR__ . '/../config/config.php';

$activityConnection = new MongoConnection(
    $config['mongo']['uri'],
    $config['mongo']['database'],
    $config['mongo']['activity_collection']
);
$userConnection = new MongoConnection(
    $config['mongo']['uri'],
    $config['mongo']['database'],
    $config['mongo']['user_collection']
);

$now = new MongoDB\BSON\UTCDateTime((int) (microtime(true) * 1000));
$blocks = ['A', 'B', 'C', 'D'];
$subjects = [
    'Matemática Aplicada',
    'Comunicação Empresarial',
    'Lógica de Programação',
    'Gestão de Projetos',
    'Direito Digital',
    'Estatística Básica',
    'Arquitetura de Software',
    'Marketing Estratégico',
    'Banco de Dados',
    'Experiência do Usuário',
];

$activityBulk = new MongoDB\Driver\BulkWrite();

for ($index = 1; $index <= 45; $index++) {
    $subject = $subjects[($index - 1) % count($subjects)] . ' ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);
    $code = 'FMU-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT);

    $activityBulk->update(
        ['codigo_disciplina' => $code],
        ['$set' => [
            'nome_disciplina' => $subject,
            'bloco' => $blocks[$index % count($blocks)],
            'ano' => 2026,
            'codigo_disciplina' => $code,
            'status' => $index % 3 === 0 ? 'Inativa' : 'Ativa',
            'data' => $now,
        ]],
        ['upsert' => true]
    );
}

$activityResult = $activityConnection->manager()->executeBulkWrite($activityConnection->namespace(), $activityBulk);

$userBulk = new MongoDB\Driver\BulkWrite();
$userBulk->update(
    ['usuario' => 'admin'],
    ['$set' => [
        'usuario' => 'admin',
        'nome' => 'Administrador',
        'senha_hash' => password_hash('admin123', PASSWORD_DEFAULT),
        'ativo' => true,
        'data' => $now,
    ]],
    ['upsert' => true]
);

$userResult = $userConnection->manager()->executeBulkWrite($userConnection->namespace(), $userBulk);

echo 'Disciplinas inseridas/atualizadas: ' . ($activityResult->getInsertedCount() + $activityResult->getModifiedCount() + $activityResult->getUpsertedCount()) . PHP_EOL;
echo 'Usuários inseridos/atualizados: ' . ($userResult->getInsertedCount() + $userResult->getModifiedCount() + $userResult->getUpsertedCount()) . PHP_EOL;
echo 'Login de teste: admin / admin123' . PHP_EOL;
