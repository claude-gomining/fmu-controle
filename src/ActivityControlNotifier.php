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
 */
final class ActivityControlNotifier
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $institution,
        private readonly int $timeoutSeconds = 5
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

    private function request(string $method, string $path, array $codes): bool
    {
        $codes = $this->sanitizeCodes($codes);

        if ($codes === []) {
            return true;
        }

        $url = rtrim($this->baseUrl, '/') . $path;
        $payload = json_encode([
            'activityId' => implode(',', $codes),
            'institution' => $this->institution,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $payload,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            error_log("Falha ao notificar o serviço LTI ({$method} {$path}): sem resposta de {$url}");

            return false;
        }

        $status = $this->responseStatus($http_response_header ?? []);

        if ($status < 200 || $status >= 300) {
            error_log("Falha ao notificar o serviço LTI ({$method} {$path}): HTTP {$status} de {$url}");

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
