<?php

namespace App\Http\Controllers\Api\App;

use App\Models\LegacyDiscipline;
use App\Models\LegacyEnrollment;
use App\Models\LegacySchoolAcademicYear;
use App\Models\LegacySchoolClass;
use App\Models\LegacySchoolClassTeacher;
use App\Models\LegacySchoolYearLock;
use App\Process;
use App\Services\DiarioControllerService;
use App\Services\ReleasePeriodService;
use App\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Checagens comuns ao diário da turma (notas e chamada), as mesmas da tela
 * antiga: permissão, escola, vínculo do professor, etapas, período de
 * lançamento e bloqueio do ano letivo.
 */
trait DiarioDaTurma
{
    use EscopoEscolas;

    /** Processo "Lançamento de notas/faltas" no controle de permissões. */
    private const PROCESSO_DIARIO = 642;

    protected function autorizar(User $user): void
    {
        if ($user->isLibrary() || !Gate::forUser($user)->allows('modify', self::PROCESSO_DIARIO)) {
            abort(403, 'Seu usuário não tem permissão para lançar notas e faltas.');
        }
    }

    /**
     * Turmas e componentes do professor no ano (componentes vazios = todos).
     * Null quando o usuário não é professor com vínculo, e vale o escopo da escola.
     *
     * @return Collection<int, array<int, int>>|null
     */
    protected function vinculosDoProfessor(User $user, int $ano): ?Collection
    {
        $vinculos = LegacySchoolClassTeacher::query()
            ->where('servidor_id', $user->getKey())
            ->where('ano', $ano)
            ->with('disciplines:id')
            ->get();

        if ($vinculos->isEmpty()) {
            return null;
        }

        return $vinculos->groupBy('turma_id')->map(fn ($grupo) => $grupo->flatMap->disciplines->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    protected function turmaPermitida(User $user, int $id): LegacySchoolClass
    {
        $turma = LegacySchoolClass::query()->where('ativo', 1)->with(['course', 'school', 'period'])->find($id);

        if (!$turma) {
            abort(404, 'Turma não encontrada.');
        }

        $escolas = $this->escolasDoUsuario($user);

        if ($escolas !== null && !in_array((int) $turma->ref_ref_cod_escola, $escolas, true)) {
            abort(403, 'Esta turma não é de uma das suas escolas.');
        }

        $doProfessor = $this->vinculosDoProfessor($user, (int) $turma->ano);

        if ($doProfessor !== null && !$doProfessor->has($turma->getKey())) {
            abort(403, 'Você não leciona nesta turma.');
        }

        return $turma;
    }

    /**
     * @return Collection<int, array{id: int, nome: string}>
     */
    protected function componentesPermitidos(User $user, LegacySchoolClass $turma): Collection
    {
        $ids = app(DiarioControllerService::class)->getComponentesTurma($turma->getKey())->pluck('id')->map(fn ($id) => (int) $id);
        $componentes = LegacyDiscipline::query()->whereIn('id', $ids)->orderBy('nome')->get(['id', 'nome'])
            ->map(fn (LegacyDiscipline $c) => ['id' => (int) $c->id, 'nome' => (string) $c->nome])
            ->values();

        $doProfessor = $this->vinculosDoProfessor($user, (int) $turma->ano);
        $permitidos = $doProfessor?->get($turma->getKey());

        return $permitidos ? $componentes->whereIn('id', $permitidos)->values() : $componentes;
    }

    /**
     * @return array<int, array{numero: int, nome: string, inicio: string|null, fim: string|null}>
     */
    protected function etapas(LegacySchoolClass $turma): array
    {
        return $turma->stages()->with('stageType')->orderBy('sequencial')->get()
            ->map(fn ($etapa) => [
                'numero' => (int) $etapa->sequencial,
                'nome' => $etapa->sequencial . 'º ' . ($etapa->stageType?->nm_tipo ?? 'etapa'),
                'inicio' => $etapa->data_inicio ? substr((string) $etapa->data_inicio, 0, 10) : null,
                'fim' => $etapa->data_fim ? substr((string) $etapa->data_fim, 0, 10) : null,
            ])
            ->values()
            ->all();
    }

    protected function etapaAtual(array $etapas): int
    {
        return $this->etapaDoDia($etapas, now()->toDateString()) ?? $etapas[0]['numero'] ?? 1;
    }

    /**
     * A etapa que contém o dia (aaaa-mm-dd), ou null se ele cai fora de todas.
     */
    protected function etapaDoDia(array $etapas, string $dia): ?int
    {
        foreach ($etapas as $etapa) {
            if ($etapa['inicio'] && $etapa['fim'] && $etapa['inicio'] <= $dia && $dia <= $etapa['fim']) {
                return $etapa['numero'];
            }
        }

        return null;
    }

    /**
     * Por que a pessoa não pode lançar agora, ou null se pode. Mesmas regras
     * do diário da tela antiga.
     */
    protected function motivoDoBloqueio(User $user, LegacySchoolClass $turma, int $etapa): ?string
    {
        $ano = (int) $turma->ano;
        $escola = (int) $turma->ref_ref_cod_escola;

        $anoLetivo = LegacySchoolAcademicYear::query()->whereSchool($escola)->whereYearEq($ano)->first(['ativo', 'andamento']);

        if ($turma->school?->bloquear_lancamento_diario_anos_letivos_encerrados == 1 && $anoLetivo && $anoLetivo->ativo == 1 && $anoLetivo->andamento == 2) {
            return "O ano letivo '{$ano}' está encerrado, esta escola está configurada para não permitir alterar o diário de anos letivos encerrados.";
        }

        $bloqueio = LegacySchoolYearLock::query()->whereInstitution((int) $turma->ref_cod_instituicao)->whereYear($ano)->first(['data_inicio', 'data_fim']);

        if ($bloqueio && (now()->toDateString() < substr((string) $bloqueio->data_inicio, 0, 10) || now()->toDateString() > substr((string) $bloqueio->data_fim, 0, 10))) {
            return 'O lançamento de notas nessa instituição está bloqueado nesta data.';
        }

        if (Gate::forUser($user)->allows('modify', Process::POST_OUT_PERIOD)) {
            return null;
        }

        try {
            $noPeriodo = (new ReleasePeriodService)->canPostNow($escola, $turma->getKey(), $etapa, $ano);
        } catch (Throwable) {
            return 'Não foi possível identificar as etapas da turma.';
        }

        return $noPeriodo ? null : 'Não é permitido realizar esta alteração fora do período de lançamento de notas/faltas.';
    }

    /**
     * Alunos enturmados na turma, mais os transferidos, na ordem da chamada.
     *
     * @return Collection<int, LegacyEnrollment>
     */
    protected function enturmacoes(LegacySchoolClass $turma): Collection
    {
        return LegacyEnrollment::query()
            ->where('ref_cod_turma', $turma->getKey())
            ->where(fn ($q) => $q->where('ativo', 1)->orWhere('transferido', true))
            ->whereHas('registration', fn ($q) => $q->where('ativo', 1)->whereHas('student', fn ($s) => $s->where('ativo', 1)))
            ->with('registration.student.person')
            ->get()
            ->sortBy(fn (LegacyEnrollment $e) => [$e->sequencial_fechamento, $e->registration->student->person->name])
            ->values();
    }
}
