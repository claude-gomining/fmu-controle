<?php

declare(strict_types=1);

/**
 * Erro da integração com o Canvas cuja mensagem pode ser exibida ao usuário
 * com segurança (código inválido, token sem permissão, blueprint inexistente).
 * Nunca deve conter o token nem detalhes internos de infraestrutura.
 */
final class CanvasException extends RuntimeException
{
}
