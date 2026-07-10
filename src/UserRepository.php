<?php

declare(strict_types=1);

final class UserRepository
{
    public function __construct(private readonly MongoConnection $connection)
    {
    }

    public function verifyCredentials(string $username, string $password): ?string
    {
        $username = trim($username);

        if ($username === '' || $password === '') {
            return null;
        }

        $query = new MongoDB\Driver\Query([
            '$or' => [
                ['usuario' => $username],
                ['username' => $username],
                ['email' => $username],
            ],
        ], [
            'limit' => 1,
        ]);

        $cursor = $this->connection->manager()->executeQuery($this->connection->namespace(), $query);
        $user = current($cursor->toArray());

        if (!is_object($user) || !$this->isActive($user)) {
            return null;
        }

        $storedPassword = $this->firstValue($user, ['senha_hash', 'password_hash', 'senha', 'password']);

        if ($storedPassword === '' || !$this->passwordMatches($password, $storedPassword)) {
            return null;
        }

        return $this->firstValue($user, ['nome', 'name', 'usuario', 'username', 'email']) ?: $username;
    }

    private function passwordMatches(string $password, string $storedPassword): bool
    {
        $info = password_get_info($storedPassword);

        if ($info['algo'] !== 0) {
            return password_verify($password, $storedPassword);
        }

        return hash_equals($storedPassword, $password);
    }

    private function isActive(object $user): bool
    {
        foreach (['ativo', 'active'] as $field) {
            if (!isset($user->{$field})) {
                continue;
            }

            $value = $user->{$field};

            if (is_bool($value)) {
                return $value;
            }

            if (is_numeric($value)) {
                return (int) $value === 1;
            }

            if (is_string($value)) {
                return in_array(strtolower($value), ['1', 'true', 'sim', 'ativo', 'active'], true);
            }
        }

        return true;
    }

    private function firstValue(object $document, array $fields): string
    {
        foreach ($fields as $field) {
            if (isset($document->{$field}) && is_scalar($document->{$field})) {
                return (string) $document->{$field};
            }
        }

        return '';
    }
}
