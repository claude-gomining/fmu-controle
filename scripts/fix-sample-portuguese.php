<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/MongoConnection.php';

$config = require __DIR__ . '/../config/config.php';

$connection = new MongoConnection(
    $config['mongo']['uri'],
    $config['mongo']['database'],
    $config['mongo']['activity_collection']
);

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

$bulk = new MongoDB\Driver\BulkWrite();

for ($index = 1; $index <= 45; $index++) {
    $code = 'FMU-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT);
    $subject = $subjects[($index - 1) % count($subjects)] . ' ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);

    $bulk->update(
        ['codigo_disciplina' => $code],
        ['$set' => ['nome_disciplina' => $subject]],
        ['multi' => false]
    );
}

$result = $connection->manager()->executeBulkWrite($connection->namespace(), $bulk);

echo 'Disciplinas ajustadas: ' . $result->getModifiedCount() . PHP_EOL;
