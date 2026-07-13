<?php

declare(strict_types=1);

final class Auth
{
    public function loginAs(string $username, string $displayName = ''): void
    {
        $username = trim($username);

        if ($username === '') {
            return;
        }

        session_regenerate_id(true);
        $_SESSION['username'] = $username;
        $displayName = trim($displayName);
        $_SESSION['user'] = $displayName !== '' ? $displayName : $username;
    }

    public function check(): bool
    {
        return isset($_SESSION['user']) && is_string($_SESSION['user']);
    }

    public function user(): ?string
    {
        return $this->check() ? $_SESSION['user'] : null;
    }

    public function username(): ?string
    {
        if (!$this->check()) {
            return null;
        }

        return isset($_SESSION['username']) && is_string($_SESSION['username'])
            ? $_SESSION['username']
            : null;
    }

    /**
     * @param list<string> $adminUsers logins de admin já em minúsculas
     */
    public function isAdmin(array $adminUsers): bool
    {
        $username = $this->username();

        return $username !== null && in_array(mb_strtolower($username), $adminUsers, true);
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
