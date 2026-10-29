<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\LegacyEnrollment;
use App\Models\LegacyRegistration;
use App\Models\LegacySchoolClass;
use App\Models\LegacyTransferRequest;
use App\Models\RegistrationStatus;
use Illuminate\Http\Request;

/**
 * Dados da tela inicial da interface nova.
 */
class PainelController extends Controller
{
    use EscopoEscolas;

    public function __invoke(Request $request)
    {
        $user = $request->user();
        $escolas = $this->escolasDoUsuario($user);
        $ano = (int) ($request->integer('ano') ?: date('Y'));

        $turmas = LegacySchoolClass::query()
            ->where('turma.ativo', 1)
            ->where('turma.ano', $ano)
            ->when($escolas !== null, fn ($q) => $q->whereIn('turma.ref_ref_cod_escola', $escolas));

        $enturmados = LegacyEnrollment::query()
            ->where('matricula_turma.ativo', 1)
            ->whereIn('ref_cod_turma', (clone $turmas)->select('cod_turma'))
            ->whereHas('registration', fn ($q) => $q->where('dependencia', false))
            ->count();

        $alunosAtivos = LegacyRegistration::query()
            ->where('matricula.ativo', 1)
            ->where('matricula.ano', $ano)
            ->where('matricula.aprovado', RegistrationStatus::ONGOING)
            ->where('matricula.dependencia', false)
            ->when($escolas !== null, fn ($q) => $q->whereIn('matricula.ref_ref_cod_escola', $escolas))
            ->count();

        $transferencias = LegacyTransferRequest::query()
            ->active()
            ->unattended()
            ->whereHas('oldRegistration', fn ($q) => $q
                ->where('ano', $ano)
                ->when($escolas !== null, fn ($q) => $q->whereIn('ref_ref_cod_escola', $escolas)))
            ->count();

        $pendencias = [];

        if ($transferencias > 0) {
            $pendencias[] = [
                'id' => 'transferencias',
                'tipo' => 'transferencia',
                'titulo' => $transferencias === 1 ? '1 pedido de transferência' : "{$transferencias} pedidos de transferência",
                'detalhe' => 'Aguardando atendimento',
                'acao' => 'Revisar',
                'link' => '/intranet/educar_transferencia_solicitacao_lst.php',
            ];
        }

        return [
            'usuario' => [
                'nome' => $user->name,
                'funcao' => $user->type?->nm_tipo,
            ],
            'anoLetivo' => $ano,
            'pendencias' => $pendencias,
            'numeros' => [
                'alunosAtivos' => $alunosAtivos,
                'presencaMes' => null,
                'turmas' => (clone $turmas)->count(),
                'vagasLivres' => max(0, (int) (clone $turmas)->sum('max_aluno') - $enturmados),
            ],
        ];
    }
}
