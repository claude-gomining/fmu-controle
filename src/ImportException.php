<?php

declare(strict_types=1);

/**
 * Erro de validação da importação de planilha cuja mensagem pode ser exibida
 * ao usuário com segurança (cabeçalho inválido, arquivo vazio, etc.).
 * Erros de infraestrutura devem usar outras exceções e não vazar detalhes.
 */
final class ImportException extends RuntimeException
{
}
