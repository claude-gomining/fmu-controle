<?php

declare(strict_types=1);

/**
 * Limita tentativas de login por chave (usuário+IP ou IP), com janela de bloqueio.
 * O estado é gravado em arquivos JSON para não depender do banco de dados.
 */
final class LoginRateLimiter
{
    public function __construct(
        private readonly string $storageDir,
        private readonly int $maxAttempts,
        private readonly int $lockoutSeconds
    ) {
    }

    public function isBlocked(string $key): bool
    {
        $state = $this->read($key);

        if ($state === null) {
            return false;
        }

        if ($this->windowExpired($state)) {
            $this->clear($key);

            return false;
        }

        return $state['attempts'] >= $this->maxAttempts;
    }

    public function registerFailure(string $key): void
    {
        $state = $this->read($key);

        if ($state === null || $this->windowExpired($state)) {
            $state = ['attempts' => 0, 'window_start' => time()];
        }

        $state['attempts']++;

        $this->write($key, $state);
    }

    public function clear(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function retryAfterSeconds(string $key): int
    {
        $state = $this->read($key);

        if ($state === null) {
            return 0;
        }

        return max(0, ($state['window_start'] + $this->lockoutSeconds) - time());
    }

    private function windowExpired(array $state): bool
    {
        return (time() - $state['window_start']) >= $this->lockoutSeconds;
    }

    private function read(string $key): ?array
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $state = json_decode($contents, true);

        if (!is_array($state) || !isset($state['attempts'], $state['window_start'])) {
            return null;
        }

        return [
            'attempts' => (int) $state['attempts'],
            'window_start' => (int) $state['window_start'],
        ];
    }

    private function write(string $key, array $state): void
    {
        @file_put_contents($this->path($key), json_encode($state), LOCK_EX);
    }

    private function path(string $key): string
    {
        return rtrim($this->storageDir, '/\\')
            . DIRECTORY_SEPARATOR
            . 'fmu_login_throttle_' . hash('sha256', $key) . '.json';
    }
}
