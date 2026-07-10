<?php

declare(strict_types=1);

final class Auth
{
    public function loginAs(string $username): void
    {
        $username = trim($username);

        if ($username === '') {
            return;
        }

        session_regenerate_id(true);
        $_SESSION['user'] = $username;
    }

    public function check(): bool
    {
        return isset($_SESSION['user']) && is_string($_SESSION['user']);
    }

    public function user(): ?string
    {
        return $this->check() ? $_SESSION['user'] : null;
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }
}
