<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\LegacyInstitution;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Quem está logado. A interface nova chama esta rota ao abrir: 401 manda
 * para a tela de login.
 */
class SessaoController extends Controller
{
    private const PROCESSO_ALUNO = 680;

    private const PROCESSO_MATRICULA = 578;

    private const PROCESSO_DIARIO = 642;

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
            // O que a tela nova mostra no menu: as mesmas permissões do tipo de usuário no i-Educar.
            'permissoes' => [
                'alunos' => !$user->isLibrary() && Gate::forUser($user)->allows('view', self::PROCESSO_ALUNO),
                'matricula' => !$user->isLibrary() && Gate::forUser($user)->allows('view', self::PROCESSO_ALUNO) && Gate::forUser($user)->allows('modify', self::PROCESSO_MATRICULA),
                'diario' => !$user->isLibrary() && Gate::forUser($user)->allows('modify', self::PROCESSO_DIARIO),
            ],
        ];
    }
}
