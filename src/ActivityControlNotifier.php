<?php

declare(strict_types=1);

/**
 * Notifica o serviço LTI de controle de atividades.
 *
 * - Criar:    POST {base_url}/v1/control/list          (registra a atividade)
 * - Ativar:   PUT  {base_url}/v1/control/enable/list
 * - Desativar:PUT  {base_url}/v1/control/disable/list
 *
 * Em todos, o payload é {"activityId": "id1,id2,...", "institution": "..."}.
 * Ao criar/importar uma atividade, chame notifyCreated ANTES de notifyEnabled.
 *
 * As listas são enviadas em LOTES (padrão: 100 IDs por requisição), para não
 * estourar timeout/limite de payload do serviço. Os IDs dos lotes que falharem
 * ficam disponíveis em lastFailedCodes().
 */
final class ActivityControlNotifier
{
    /** @var list<string> IDs dos lotes que falharam na última chamada */
    private array $lastFailedCodes = [];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $institution,
        private readonly int $timeoutSeconds = 5,
        private readonly int $batchSize = 100
    ) {
    }

    public function notifyCreated(array $codes): bool
    {
        return $this->request('POST', '/v1/control/list', $codes);
    }

    public function notifyEnabled(array $codes): bool
    {
        return $this->request('PUT', '/v1/control/enable/list', $codes);
    }

    public function notifyDisabled(array $codes): bool
    {
        return $this->request('PUT', '/v1/control/disable/list', $codes);
    }

    /**
     * IDs que não puderam ser enviados na última chamada (lotes com falha).
     *
     * @return list<string>
     */
    public function lastFailedCodes(): array
    {
        return $this->lastFailedCodes;
    }

    /**
     * Fluxo de criação: registra (POST /v1/control/list) e depois ativa ou
     * desativa apenas os IDs que foram registrados com sucesso.
     *
     * @param list<string|int> $codes
     * @return list<string> IDs que falharam (vazio = todos processados)
     */
    public function registerAndApply(array $codes, bool $enable): array
    {
        $codes = $this->sanitizeCodes($codes);

        if ($codes === []) {
            return [];
        }

        $this->notifyCreated($codes);
        $failed = $this->lastFailedCodes();

        // Só ativa/desativa o que foi registrado com sucesso.
        $registered = array_values(array_diff($codes, $failed));

        if ($registered !== []) {
            $enable ? $this->notifyEnabled($registered) : $this->notifyDisabled($registered);
            $failed = array_merge($failed, $this->lastFailedCodes());
        }

        return array_values(array_unique($failed));
    }

    private function request(string $method, string $path, array $codes): bool
    {
        $this->lastFailedCodes = [];
        $codes = $this->sanitizeCodes($codes);

        if ($codes === []) {
            return true;
        }

        $batchSize = max(1, $this->batchSize);
        $ok = true;

        foreach (array_chunk($codes, $batchSize) as $batch) {
            if (!$this->sendBatch($method, $path, $batch)) {
                $ok = false;
                array_push($this->lastFailedCodes, ...$batch);
            }
        }

        return $ok;
    }

    /**
     * @param list<string> $codes
     */
    private function sendBatch(string $method, string $path, array $codes): bool
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $payload = json_encode([
            'activityId' => implode(',', $codes),
            'institution' => $this->institution,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                // User-Agent explícito: o PHP não envia por padrão e alguns
                // WAF/proxies rejeitam (403) requisições sem esse header.
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n"
                    . "User-Agent: FMU-Portal/1.0 (+PHP)\r\n",
                'content' => $payload,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $count = count($codes);
        $first = $codes[0] ?? '';
        $last = $codes[$count - 1] ?? '';

        if ($body === false) {
            error_log("Falha ao notificar o serviço LTI ({$method} {$path}): sem resposta; lote de {$count} ({$first}..{$last})");

            return false;
        }

        $status = $this->responseStatus($http_response_header ?? []);

        if ($status < 200 || $status >= 300) {
            error_log("Falha ao notificar o serviço LTI ({$method} {$path}): HTTP {$status}; lote de {$count} ({$first}..{$last})");

            return false;
        }

        return true;
    }

    private function sanitizeCodes(array $codes): array
    {
        $sanitized = [];

        foreach ($codes as $code) {
            if (!is_scalar($code)) {
                continue;
            }

            $code = trim((string) $code);

            if ($code !== '') {
                $sanitized[] = $code;
            }
        }

        return array_values(array_unique($sanitized));
    }

    private function responseStatus(array $responseHeaders): int
    {
        $statusLine = (string) ($responseHeaders[0] ?? '');

        if (preg_match('{^HTTP/\S+\s+(\d{3})}', $statusLine, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
