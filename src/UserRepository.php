<?php

declare(strict_types=1);

final class UserRepository
{
    /**
     * Hash sem correspondência possível, usado para equalizar o tempo de resposta
     * quando o usuário não existe ou não possui hash válido.
     */
    private const DUMMY_HASH = '$2y$12$zSvufOGsz4Ge2DzXWqGzcupadq0Tbrj28.xHmMFkjWG08VMBfuuXa';

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
            password_verify($password, self::DUMMY_HASH);

            return null;
        }

        $storedHash = $this->firstValue($user, ['senha_hash', 'password_hash']);

        if (!$this->isSupportedHash($storedHash)) {
            password_verify($password, self::DUMMY_HASH);

            return null;
        }

        if (!password_verify($password, $storedHash)) {
            return null;
        }

        return $this->firstValue($user, ['nome', 'name', 'usuario', 'username', 'email']) ?: $username;
    }

    private function isSupportedHash(string $storedHash): bool
    {
        if ($storedHash === '') {
            return false;
        }

        $algo = password_get_info($storedHash)['algo'] ?? null;

        return $algo !== null && $algo !== 0;
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

        return false;
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
