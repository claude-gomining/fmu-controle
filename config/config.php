<?php

declare(strict_types=1);

function env_value(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === '') {
        return $default;
    }

    return (string) $value;
}

return [
    'app_name' => 'FMU - Correção Automática',
    'timezone' => env_value('APP_TIMEZONE', 'America/Sao_Paulo'),
    'session_name' => env_value('APP_SESSION_NAME', 'fmu_auto_grading_portal'),

    'mongo' => [
        'uri' => env_value('MONGODB_URI', 'mongodb://127.0.0.1:27017'),
        'database' => env_value('MONGODB_DATABASE', 'activity'),
        'activity_collection' => env_value('MONGODB_ACTIVITY_COLLECTION', 'fmu_activity_control'),
        'user_collection' => env_value('MONGODB_USER_COLLECTION', 'fmu_user_control'),
    ],
];
