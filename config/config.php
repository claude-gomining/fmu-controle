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

    'canvas' => [
        'base_url' => env_value('CANVAS_BASE_URL', 'https://afya.test.instructure.com'),
        'token' => env_value('CANVAS_API_TOKEN', ''),
        'timeout_seconds' => max(1, (int) env_value('CANVAS_TIMEOUT_SECONDS', '20')),
        'per_page' => min(100, max(1, (int) env_value('CANVAS_PER_PAGE', '100'))),
        'max_pages' => max(1, (int) env_value('CANVAS_MAX_PAGES', '200')),
        'collection' => env_value('MONGODB_CANVAS_COLLECTION', 'canvas_blueprints'),
        'page_size' => max(1, (int) env_value('CANVAS_PAGE_SIZE', '10')),
    ],

    'admin' => [
        // Logins com acesso à página de administração (comparação sem distinção de maiúsculas).
        'users' => array_values(array_filter(
            array_map(
                static fn (string $user): string => mb_strtolower(trim($user)),
                explode(',', (string) env_value('ADMIN_USERS', 'gomining'))
            ),
            static fn (string $user): bool => $user !== ''
        )),
    ],

    'upload' => [
        'max_bytes' => max(1024, (int) env_value('UPLOAD_MAX_BYTES', (string) (5 * 1024 * 1024))),
        'max_rows' => max(1, (int) env_value('UPLOAD_MAX_ROWS', '10000')),
    ],

    'lti_control' => [
        'base_url' => env_value(
            'LTI_CONTROL_BASE_URL',
            'http://prd-lti-activity-control.eba-ikyyadp3.us-east-2.elasticbeanstalk.com'
        ),
        'institution' => env_value('LTI_CONTROL_INSTITUTION', 'fmu'),
        'timeout_seconds' => max(1, (int) env_value('LTI_CONTROL_TIMEOUT_SECONDS', '5')),
    ],

    'security' => [
        'login_max_attempts' => max(1, (int) env_value('LOGIN_MAX_ATTEMPTS', '5')),
        'login_ip_max_attempts' => max(1, (int) env_value('LOGIN_IP_MAX_ATTEMPTS', '30')),
        'login_lockout_seconds' => max(60, (int) env_value('LOGIN_LOCKOUT_SECONDS', '900')),
        'throttle_dir' => env_value('LOGIN_THROTTLE_DIR', sys_get_temp_dir()),
    ],
];
