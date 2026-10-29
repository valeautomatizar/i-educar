<?php

namespace App\Services\App;

use RuntimeException;

/**
 * Uma regra de matrícula não foi cumprida. A mensagem é para quem está
 * matriculando; `precisaConfirmar` indica um aviso que a pessoa pode aceitar.
 */
class MatriculaRecusada extends RuntimeException
{
    public function __construct(string $mensagem, public readonly bool $precisaConfirmar = false)
    {
        parent::__construct($mensagem);
    }
}
