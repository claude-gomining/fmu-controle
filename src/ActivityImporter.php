<?php

declare(strict_types=1);

/**
 * Lê uma planilha CSV (separada por ponto-e-vírgula, padrão do Excel em
 * português) e converte cada linha em uma disciplina pronta para cadastro.
 *
 * Cabeçalho obrigatório, nesta ordem exata: CRT;DISCIPLINA;BLOCO;ANO
 *   - CRT ........ nome/código da oferta -> codigo_disciplina (indexador)
 *   - DISCIPLINA . nome da disciplina    -> nome_disciplina
 *   - BLOCO ...... bloco                 -> bloco
 *   - ANO ........ ano                   -> ano
 *
 * Cada célula sofre trim. Linhas em branco são ignoradas; linhas sem CRT são
 * contadas como inválidas; CRTs repetidos dentro do arquivo são contados como
 * duplicados (apenas a primeira ocorrência é considerada).
 */
final class ActivityImporter
{
    public const EXPECTED_HEADER = ['CRT', 'DISCIPLINA', 'BLOCO', 'ANO'];

    public function __construct(private readonly int $maxRows = 10000)
    {
    }

    /**
     * @return array{activities: list<array<string, mixed>>, invalid: int, duplicates: int, dataRows: int}
     */
    public function parseCsv(string $filePath): array
    {
        $content = @file_get_contents($filePath);

        if ($content === false) {
            throw new ImportException('Não foi possível ler o arquivo enviado.');
        }

        if (trim($content) === '') {
            throw new ImportException('O arquivo enviado está vazio.');
        }

        // Excel em português costuma gravar CSV em Windows-1252; normaliza para UTF-8.
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        // Remove BOM UTF-8 do início, se houver.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = fgetcsv($stream, 0, ';', '"', '');

        if (!is_array($header)) {
            fclose($stream);
            throw new ImportException('O arquivo não contém um cabeçalho válido.');
        }

        $header = array_map(
            static fn ($cell): string => mb_strtoupper(trim((string) ($cell ?? ''))),
            $header
        );

        if ($header !== self::EXPECTED_HEADER) {
            fclose($stream);
            throw new ImportException(
                'Cabeçalho inválido. A primeira linha deve ser exatamente: '
                . implode(';', self::EXPECTED_HEADER) . '.'
            );
        }

        $activities = [];
        $invalid = 0;
        $duplicates = 0;
        $dataRows = 0;
        $seen = [];

        while (($row = fgetcsv($stream, 0, ';', '"', '')) !== false) {
            $cells = array_map(static fn ($cell): string => trim((string) ($cell ?? '')), (array) $row);

            // Ignora linhas totalmente em branco.
            if (implode('', $cells) === '') {
                continue;
            }

            $dataRows++;

            if ($dataRows > $this->maxRows) {
                fclose($stream);
                throw new ImportException('O arquivo excede o limite de ' . $this->maxRows . ' linhas.');
            }

            $codigo = $cells[0] ?? '';

            if ($codigo === '') {
                $invalid++;
                continue;
            }

            $key = mb_strtolower($codigo);

            if (isset($seen[$key])) {
                $duplicates++;
                continue;
            }

            $seen[$key] = true;

            $ano = $cells[3] ?? '';

            $activities[] = [
                'codigo_disciplina' => $codigo,
                'nome_disciplina' => $cells[1] ?? '',
                'bloco' => $cells[2] ?? '',
                'ano' => ctype_digit($ano) ? (int) $ano : $ano,
            ];
        }

        fclose($stream);

        return [
            'activities' => $activities,
            'invalid' => $invalid,
            'duplicates' => $duplicates,
            'dataRows' => $dataRows,
        ];
    }
}
