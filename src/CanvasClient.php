<?php

declare(strict_types=1);

/**
 * Cliente da API do Canvas para buscar os cursos associados a uma blueprint.
 *
 * GET {base}/api/v1/courses/{blueprintId}/blueprint_templates/default/associated_courses
 *
 * A API do Canvas pagina os resultados via header `Link` (rel="next"); este
 * cliente segue automaticamente todas as páginas. O token é enviado no header
 * Authorization e nunca é registrado em log.
 */
final class CanvasClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeoutSeconds = 20,
        private readonly int $perPage = 100,
        private readonly int $maxPages = 200
    ) {
    }

    /**
     * @return list<array{course_id:int,name:string,course_code:string,sis_course_id:string,term_name:string}>
     */
    public function fetchAssociatedCourses(string $blueprintId): array
    {
        $blueprintId = trim($blueprintId);

        if ($blueprintId === '' || !ctype_digit($blueprintId)) {
            throw new CanvasException('Código de blueprint inválido. Informe apenas o número do curso da blueprint.');
        }

        if ($this->token === '') {
            throw new CanvasException('Token do Canvas não configurado. Defina a variável CANVAS_API_TOKEN.');
        }

        $baseHost = parse_url($this->baseUrl, PHP_URL_HOST);
        $url = rtrim($this->baseUrl, '/')
            . '/api/v1/courses/' . $blueprintId
            . '/blueprint_templates/default/associated_courses?per_page=' . $this->perPage;

        $courses = [];
        $pages = 0;

        while ($url !== null && $pages < $this->maxPages) {
            $pages++;
            $response = $this->httpGet($url);

            if ($response['status'] === 401 || $response['status'] === 403) {
                throw new CanvasException('Token do Canvas inválido ou sem permissão para esta blueprint.');
            }

            if ($response['status'] === 404) {
                throw new CanvasException('Blueprint não encontrada no Canvas. Verifique o código informado.');
            }

            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new CanvasException('O Canvas retornou um erro ao buscar os cursos (HTTP ' . $response['status'] . ').');
            }

            $decoded = json_decode($response['body'], true);

            if (!is_array($decoded)) {
                throw new CanvasException('Resposta inesperada do Canvas ao buscar os cursos.');
            }

            foreach ($decoded as $item) {
                if (!is_array($item) || !isset($item['id'])) {
                    continue;
                }

                $courses[] = [
                    'course_id' => (int) $item['id'],
                    'name' => trim((string) ($item['name'] ?? '')),
                    'course_code' => trim((string) ($item['course_code'] ?? '')),
                    'sis_course_id' => trim((string) ($item['sis_course_id'] ?? '')),
                    'term_name' => trim((string) ($item['term_name'] ?? '')),
                ];
            }

            $next = $this->nextLink($response['headers']);

            // Defesa contra SSRF: só segue links que permaneçam no mesmo host.
            if ($next !== null && parse_url($next, PHP_URL_HOST) !== $baseHost) {
                break;
            }

            $url = $next;
        }

        return $courses;
    }

    /**
     * @return array{status:int, headers:list<string>, body:string}
     */
    private function httpGet(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$this->token}\r\nAccept: application/json\r\n",
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];

        if ($body === false) {
            throw new CanvasException('Não foi possível contatar o Canvas. Verifique a conexão e tente novamente.');
        }

        return [
            'status' => $this->statusFromHeaders($headers),
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /**
     * @param list<string> $headers
     */
    private function statusFromHeaders(array $headers): int
    {
        // Em caso de redirecionamentos, a última linha de status é a que vale.
        $status = 0;

        foreach ($headers as $header) {
            if (preg_match('{^HTTP/\S+\s+(\d{3})}', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }

    /**
     * Extrai a URL com rel="next" do header Link do Canvas.
     *
     * @param list<string> $headers
     */
    private function nextLink(array $headers): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Link:') !== 0) {
                continue;
            }

            $value = trim(substr($header, strlen('Link:')));

            foreach (explode(',', $value) as $part) {
                if (preg_match('/<([^>]+)>\s*;\s*rel="?next"?/i', $part, $matches) === 1) {
                    return $matches[1];
                }
            }
        }

        return null;
    }
}
