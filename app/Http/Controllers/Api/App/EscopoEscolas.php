<?php

namespace App\Http\Controllers\Api\App;

use App\User;

/**
 * Escolas que o usuário pode ver. Administrador e usuário institucional veem
 * todas (null); usuário de escola vê só as escolas vinculadas a ele.
 */
trait EscopoEscolas
{
    /**
     * @return array<int, int>|null
     */
    protected function escolasDoUsuario(User $user): ?array
    {
        if ($user->isAdmin() || $user->isInstitutional()) {
            return null;
        }

        return $user->schools()->pluck('cod_escola')->map(fn ($id) => (int) $id)->all();
    }
}
