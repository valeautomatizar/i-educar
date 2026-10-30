<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\DailyAttendance;
use App\Models\LegacyEnrollment;
use App\Models\LegacyRegistration;
use App\Models\LegacySchoolClass;
use App\Models\LegacySchoolClassTeacher;
use App\Models\LegacyTransferRequest;
use App\Models\RegistrationStatus;
use App\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

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
        $ano = (int) ($request->integer('ano') ?: now()->year);

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

        // Transferência é trabalho da secretaria: só aparece para quem pode matricular.
        if ($transferencias > 0 && Gate::forUser($user)->allows('modify', 578)) {
            $pendencias[] = [
                'id' => 'transferencias',
                'tipo' => 'transferencia',
                'titulo' => $transferencias === 1 ? '1 pedido de transferência' : "{$transferencias} pedidos de transferência",
                'detalhe' => 'Aguardando atendimento',
                'acao' => 'Revisar',
                'link' => '/intranet/educar_transferencia_solicitacao_lst.php',
            ];
        }

        $semChamada = $this->turmasSemChamadaDeHoje($user, $turmas, $ano);

        if ($semChamada->isNotEmpty()) {
            $nomes = $semChamada->take(3)->implode(', ');
            $pendencias[] = [
                'id' => 'chamada',
                'tipo' => 'chamada',
                'titulo' => $semChamada->count() === 1 ? 'Turma sem chamada de hoje' : 'Turmas sem chamada de hoje',
                'detalhe' => $semChamada->count() > 3 ? $nomes . ' e mais ' . ($semChamada->count() - 3) : $nomes,
                'acao' => 'Fazer chamada',
                'link' => '/frequencia',
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
                'presencaMes' => $this->presencaNoMes($turmas),
                'turmas' => (clone $turmas)->count(),
                'vagasLivres' => max(0, (int) (clone $turmas)->sum('max_aluno') - $enturmados),
            ],
        ];
    }

    /**
     * Porcentagem de presença nas chamadas do mês nas turmas do usuário, ou
     * null se ainda não houve chamada no mês.
     */
    private function presencaNoMes(Builder $turmas): ?int
    {
        $chamadas = DailyAttendance::query()
            ->whereIn('school_class_id', (clone $turmas)->select('cod_turma'))
            ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->toDateString()])
            ->selectRaw('count(*) as total, count(*) filter (where present) as presentes')
            ->first();

        return $chamadas && $chamadas->total > 0 ? (int) round(100 * $chamadas->presentes / $chamadas->total) : null;
    }

    /**
     * Nos dias de semana do ano corrente, as turmas que ainda não têm a
     * chamada de hoje. Para o professor, as turmas dele; para os demais, só
     * as turmas que já fazem a chamada pela interface nova.
     *
     * @return Collection<int, string>
     */
    private function turmasSemChamadaDeHoje(User $user, Builder $turmas, int $ano): Collection
    {
        if ($ano !== now()->year || now()->isWeekend()) {
            return collect();
        }

        $doProfessor = LegacySchoolClassTeacher::query()->where('servidor_id', $user->getKey())->where('ano', $ano)->pluck('turma_id');

        return (clone $turmas)
            ->when(
                $doProfessor->isNotEmpty(),
                fn ($q) => $q->whereIn('cod_turma', $doProfessor),
                fn ($q) => $q->whereIn('cod_turma', DailyAttendance::query()->whereYear('date', $ano)->select('school_class_id')),
            )
            ->whereNotIn('cod_turma', DailyAttendance::query()->whereDate('date', now()->toDateString())->select('school_class_id'))
            ->orderBy('nm_turma')
            ->pluck('nm_turma');
    }
}
