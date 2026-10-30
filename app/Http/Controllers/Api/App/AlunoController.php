<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\DailyAttendance;
use App\Models\LegacyRegistration;
use App\Models\LegacyStudent;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Busca e ficha do aluno para a interface nova.
 */
class AlunoController extends Controller
{
    use EscopoEscolas;

    /** Processo "Alunos" no controle de permissões. */
    private const PROCESSO_ALUNO = 680;

    public function index(Request $request)
    {
        $this->autorizar($request->user());

        $busca = trim((string) $request->query('busca', ''));

        if (mb_strlen($busca) < 2) {
            return ['alunos' => []];
        }

        $escolas = $this->escolasDoUsuario($request->user());
        $digitos = preg_replace('/\D/', '', $busca);

        $alunos = LegacyStudent::query()
            ->where('aluno.ativo', 1)
            ->join('cadastro.pessoa', 'pessoa.idpes', '=', 'aluno.ref_idpes')
            ->join('cadastro.fisica', 'fisica.idpes', '=', 'aluno.ref_idpes')
            ->where(function ($q) use ($busca, $digitos) {
                $q->whereUnaccent('pessoa.nome', $busca);

                // CPF digitado com ou sem pontuação.
                if (strlen($digitos) === 11) {
                    $q->orWhere('fisica.cpf', (int) $digitos);
                }
            })
            ->when($escolas !== null, fn ($q) => $q->whereHas('registrations', fn ($r) => $r->whereIn('ref_ref_cod_escola', $escolas)))
            ->with(['lastRegistration.grade', 'lastRegistration.school.person', 'lastRegistration.school.organization'])
            ->orderBy('pessoa.nome')
            ->limit(20)
            ->get(['aluno.cod_aluno', 'aluno.ref_idpes', 'pessoa.nome', 'fisica.data_nasc']);

        return [
            'alunos' => $alunos->map(fn (LegacyStudent $aluno) => [
                'id' => $aluno->cod_aluno,
                'nome' => $aluno->nome,
                'detalhe' => $this->detalheBusca($aluno),
            ])->values(),
        ];
    }

    public function show(Request $request, int $aluno)
    {
        $this->autorizar($request->user());

        $escolas = $this->escolasDoUsuario($request->user());

        $student = LegacyStudent::query()
            ->with(['person', 'individual', 'registrations' => fn ($q) => $q->where('ativo', 1)->orderByDesc('ano')->orderByDesc('cod_matricula')])
            ->findOrFail($aluno);

        $matriculas = $student->registrations;

        if ($escolas !== null && $matriculas->whereIn('ref_ref_cod_escola', $escolas)->isEmpty()) {
            abort(403, 'Este aluno não é de uma das suas escolas.');
        }

        $matriculas->load(['grade', 'school.person', 'school.organization', 'lastEnrollment.schoolClass.period']);

        /** @var LegacyRegistration|null $atual */
        $atual = $matriculas->first();
        $turma = $atual?->lastEnrollment?->schoolClass;
        $nascimento = $student->individual?->data_nasc;

        return [
            'id' => $student->cod_aluno,
            'nome' => $student->person->name,
            'nascimento' => $nascimento?->format('Y-m-d'),
            'turma' => $turma?->nm_turma ?? $atual?->grade?->name,
            'turno' => $turma?->period?->name,
            'matricula' => $atual ? (string) $atual->cod_matricula : null,
            'situacao' => $atual ? SituacaoMatricula::grupo((int) $atual->aprovado) : null,
            'situacaoTexto' => $atual ? SituacaoMatricula::texto((int) $atual->aprovado) : null,
            'presenca' => $this->presenca($atual),
            'faltas' => $this->faltasNoAno($atual),
            'media' => $this->mediaGeral($atual),
            'responsavel' => $student->getGuardianName(),
            'transporte' => null,
            'alertas' => [],
            'historico' => $matriculas->map(fn (LegacyRegistration $m) => [
                'ano' => (int) $m->ano,
                'serie' => $m->grade?->name,
                'escola' => $m->school?->name,
                'situacao' => SituacaoMatricula::grupo((int) $m->aprovado),
                'situacaoTexto' => SituacaoMatricula::texto((int) $m->aprovado),
            ])->values(),
        ];
    }

    private function autorizar(User $user): void
    {
        if ($user->isLibrary() || !Gate::forUser($user)->allows('view', self::PROCESSO_ALUNO)) {
            abort(403, 'Seu usuário não tem permissão para ver o cadastro de alunos.');
        }
    }

    /**
     * Média das médias dos componentes na matrícula atual, ou null sem notas.
     */
    private function mediaGeral(?LegacyRegistration $matricula): ?float
    {
        if (!$matricula) {
            return null;
        }

        $media = DB::table('modules.nota_componente_curricular_media as media')
            ->join('modules.nota_aluno', 'nota_aluno.id', '=', 'media.nota_aluno_id')
            ->where('nota_aluno.matricula_id', $matricula->getKey())
            // Média conceitual (letra) fica de fora.
            ->whereRaw("media.media_arredondada ~ '^[0-9]+(\\.[0-9]+)?$'")
            ->avg(DB::raw('media.media_arredondada::numeric'));

        return $media === null ? null : round((float) $media, 1);
    }

    /**
     * Porcentagem de presença nas chamadas diárias da matrícula atual, ou
     * null se a escola ainda não faz a chamada pela interface nova.
     */
    private function presenca(?LegacyRegistration $matricula): ?int
    {
        if (!$matricula) {
            return null;
        }

        $chamadas = DailyAttendance::query()
            ->where('registration_id', $matricula->getKey())
            ->selectRaw('count(*) as total, count(*) filter (where present) as presentes')
            ->first();

        return $chamadas && $chamadas->total > 0 ? (int) round(100 * $chamadas->presentes / $chamadas->total) : null;
    }

    /**
     * Faltas lançadas no boletim da matrícula atual, somando as etapas (no
     * geral ou por componente, conforme a regra), ou null sem lançamento.
     */
    private function faltasNoAno(?LegacyRegistration $matricula): ?int
    {
        if (!$matricula) {
            return null;
        }

        $faltaAluno = DB::table('modules.falta_aluno')->where('matricula_id', $matricula->getKey())->pluck('id');

        if ($faltaAluno->isEmpty()) {
            return null;
        }

        $geral = DB::table('modules.falta_geral')->whereIn('falta_aluno_id', $faltaAluno);
        $componente = DB::table('modules.falta_componente_curricular')->whereIn('falta_aluno_id', $faltaAluno);

        if (!$geral->exists() && !$componente->exists()) {
            return null;
        }

        return (int) $geral->sum('quantidade') + (int) $componente->sum('quantidade');
    }

    private function detalheBusca(LegacyStudent $aluno): string
    {
        $partes = [];

        if ($aluno->data_nasc) {
            $partes[] = Carbon::parse($aluno->data_nasc)->age . ' anos';
        }

        $ultima = $aluno->lastRegistration;

        $partes[] = $ultima
            ? SituacaoMatricula::texto((int) $ultima->aprovado) . ' no ' . ($ultima->grade?->name ?? 'série não informada') . ' em ' . $ultima->ano . ($ultima->school?->name ? ', ' . $ultima->school->name : '')
            : 'sem matrícula na rede';

        return implode(' · ', $partes);
    }
}
