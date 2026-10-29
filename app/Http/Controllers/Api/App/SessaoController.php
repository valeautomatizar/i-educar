<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\LegacyInstitution;
use Illuminate\Http\Request;

/**
 * Quem está logado. A interface nova chama esta rota ao abrir: 401 manda
 * para a tela de login.
 */
class SessaoController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user()->load('type', 'employee');
        $instituicao = app(LegacyInstitution::class);

        return [
            'usuario' => [
                'id' => $user->cod_usuario,
                'nome' => $user->name,
                'funcao' => $user->type?->nm_tipo,
            ],
            'instituicao' => [
                'nome' => $instituicao->nm_instituicao,
                'cidade' => $instituicao->cidade ? $instituicao->cidade . '/' . $instituicao->ref_sigla_uf : null,
            ],
            // Quem tem senha fraca ou vencida precisa trocá-la antes de usar o sistema.
            'trocarSenha' => (bool) $user->employee?->force_reset_password,
            'linkTrocarSenha' => route('change-password', [], false),
        ];
    }
}
