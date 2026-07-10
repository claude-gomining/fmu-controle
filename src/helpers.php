<?php

declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect_to(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function verify_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['_csrf_token'])
        && hash_equals($_SESSION['_csrf_token'], $token);
}

function flash_set(string $type, string $message): void
{
    $_SESSION['_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function flash_get(): ?array
{
    if (empty($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
        return null;
    }

    $flash = $_SESSION['_flash'];
    unset($_SESSION['_flash']);

    return $flash;
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' https://fonts.googleapis.com; "
        . "font-src https://fonts.gstatic.com; "
        . "img-src 'self' data:; "
        . "object-src 'none'; "
        . "frame-ancestors 'none'; "
        . "form-action 'self'; "
        . "base-uri 'self'"
    );

    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function current_url(array $override = []): string
{
    $query = array_merge($_GET, $override);

    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    $queryString = http_build_query($query);

    return 'index.php' . ($queryString ? '?' . $queryString : '');
}
