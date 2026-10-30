<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Controllers\FaltasComponenteController;
use App\Http\Controllers\FaltasGeralController;
use App\Http\Requests\PostFaltaComponenteRequest;
use App\Http\Requests\PostFaltaGeralRequest;
use App\Models\DailyAttendance;
use App\Models\LegacyEnrollment;
use App\Models\LegacySchoolClass;
use App\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RegraAvaliacao_Model_TipoPresenca;
use Throwable;

/**
 * Chamada diária da interface nova. Cada dia de aula guarda a presença de
 * cada aluno; ao salvar, as faltas da etapa no boletim passam a ser a soma
 * das faltas marcadas na chamada, gravadas pelo mesmo lançamento da API v2
 * de faltas (regra de avaliação, etapa e promoção do aluno).
 */
class ChamadaController extends Controller
{
    use DiarioDaTurma;

    public function turmas(Request $request)
    {
        $user = $request->user();
        $this->autorizar($user);

        $ano = (int) ($request->validate(['ano' => ['nullable', 'integer', 'between:2000,2100']])['ano'] ?? date('Y'));
        $doProfessor = $this->vinculosDoProfessor($user, $ano);
        $escolas = $this->escolasDoUsuario($user);

        $turmas = LegacySchoolClass::query()
            ->where('ativo', 1)
            ->where('ano', $ano)
            ->when($doProfessor !== null, fn ($q) => $q->whereIn('cod_turma', $doProfessor->keys()))
            ->when($escolas !== null, fn ($q) => $q->whereIn('ref_ref_cod_escola', $escolas))
            ->with(['period', 'grade', 'school.organization', 'school.person'])
            ->orderBy('nm_turma')
            ->limit(100)
            ->get();

        $feitasHoje = DailyAttendance::query()
            ->whereIn('school_class_id', $turmas->modelKeys())
            ->whereDate('date', now()->toDateString())
            ->distinct()
            ->pluck('school_class_id')
            ->map(fn ($id) => (int) $id);

        return [
            'ano' => $ano,
            'turmas' => $turmas->map(fn (LegacySchoolClass $turma) => [
                'id' => $turma->cod_turma,
                'nome' => $turma->nm_turma,
                'serie' => $turma->grade?->name,
                'escola' => $turma->school?->name,
                'turno' => $turma->period?->name,
                'chamadaDeHoje' => $feitasHoje->contains($turma->cod_turma),
            ])->values(),
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $this->autorizar($user);

        $dados = $request->validate([
            'turma' => ['required', 'integer'],
            'data' => ['nullable', 'date_format:Y-m-d'],
            'componente' => ['nullable', 'integer'],
        ]);

        $turma = $this->turmaPermitida($user, (int) $dados['turma']);
        $dia = $dados['data'] ?? now()->toDateString();
        [$porComponente, $componentes, $componenteId] = $this->componenteDaChamada($user, $turma, $dados['componente'] ?? null);

        $etapas = $this->etapas($turma);
        $etapa = $this->etapaDoDia($etapas, $dia);
        $motivo = $this->motivoParaNaoLancar($user, $turma, $dia, $etapa);

        $enturmacoes = $this->alunosNoDia($turma, $dia);
        $matriculas = $enturmacoes->pluck('ref_cod_matricula');

        $doDia = DailyAttendance::query()
            ->where('school_class_id', $turma->getKey())
            ->whereIn('registration_id', $matriculas)
            ->where('discipline_id', $componenteId)
            ->whereDate('date', $dia)
            ->pluck('present', 'registration_id');

        $faltasNaEtapa = $etapa === null ? collect() : DailyAttendance::query()
            ->whereIn('registration_id', $matriculas)
            ->where('discipline_id', $componenteId)
            ->where('stage', $etapa)
            ->where('present', false)
            ->groupBy('registration_id')
            ->select('registration_id', DB::raw('count(*) as faltas'))
            ->pluck('faltas', 'registration_id');

        return [
            'turma' => ['id' => $turma->cod_turma, 'nome' => $turma->nm_turma, 'turno' => $turma->period?->name],
            'data' => $dia,
            'porComponente' => $porComponente,
            'componentes' => $componentes->values(),
            'componente' => $componenteId,
            'etapa' => $etapa === null ? null : collect($etapas)->firstWhere('numero', $etapa),
            'feita' => $doDia->isNotEmpty(),
            'podeLancar' => $motivo === null,
            'motivo' => $motivo,
            'alunos' => $enturmacoes->values()->map(fn (LegacyEnrollment $e, int $i) => [
                'alunoId' => (int) $e->registration->ref_cod_aluno,
                'matriculaId' => (int) $e->ref_cod_matricula,
                'numero' => $i + 1,
                'nome' => $e->registration->student->person->name,
                'presente' => $doDia->has($e->ref_cod_matricula) ? (bool) $doDia[$e->ref_cod_matricula] : null,
                'faltasNaEtapa' => (int) ($faltasNaEtapa[$e->ref_cod_matricula] ?? 0),
            ])->values(),
        ];
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $this->autorizar($user);

        $dados = $request->validate([
            'turma' => ['required', 'integer'],
            'data' => ['required', 'date_format:Y-m-d'],
            'componente' => ['nullable', 'integer'],
            'faltaram' => ['present', 'array'],
            'faltaram.*' => ['integer'],
        ], [
            'data.required' => 'Informe o dia da chamada.',
            'data.date_format' => 'O dia da chamada precisa estar no formato aaaa-mm-dd.',
        ]);

        $turma = $this->turmaPermitida($user, (int) $dados['turma']);
        $dia = $dados['data'];
        [$porComponente, , $componenteId] = $this->componenteDaChamada($user, $turma, $dados['componente'] ?? null);

        $etapa = $this->etapaDoDia($this->etapas($turma), $dia);

        if ($motivo = $this->motivoParaNaoLancar($user, $turma, $dia, $etapa)) {
            return response()->json(['message' => $motivo], 422);
        }

        $enturmacoes = $this->alunosNoDia($turma, $dia);
        $faltaram = collect($dados['faltaram'])->map(fn ($id) => (int) $id);
        $daTurma = $enturmacoes->map(fn (LegacyEnrollment $e) => (int) $e->registration->ref_cod_aluno);

        if ($estranhos = $faltaram->diff($daTurma)->values()->all()) {
            return response()->json(['message' => 'Há alunos na lista de faltas que não estão nesta turma neste dia.', 'alunos' => $estranhos], 422);
        }

        DB::transaction(function () use ($enturmacoes, $faltaram, $turma, $componenteId, $dia, $etapa, $user) {
            foreach ($enturmacoes as $e) {
                DailyAttendance::query()->updateOrCreate([
                    'school_class_id' => $turma->getKey(),
                    'registration_id' => $e->ref_cod_matricula,
                    'discipline_id' => $componenteId,
                    'date' => $dia,
                ], [
                    'stage' => $etapa,
                    'present' => !$faltaram->contains((int) $e->registration->ref_cod_aluno),
                    'user_id' => $user->getKey(),
                ]);
            }
        });

        $avisos = $this->atualizarBoletins($request, $turma, $enturmacoes, $porComponente, $componenteId, $etapa);

        return response()->json([
            'message' => $avisos ? 'Chamada salva, mas algumas faltas não foram para o boletim.' : 'Chamada salva com sucesso.',
            'presentes' => $enturmacoes->count() - $faltaram->count(),
            'faltas' => $faltaram->count(),
            'avisos' => $avisos,
        ], 201);
    }

    /**
     * Se a chamada é por componente (pela regra de avaliação da turma), os
     * componentes que a pessoa pode usar e o escolhido (null no geral).
     *
     * @return array{0: bool, 1: Collection<int, array{id: int, nome: string}>, 2: int|null}
     */
    private function componenteDaChamada(User $user, LegacySchoolClass $turma, mixed $pedido): array
    {
        $regra = $turma->getEvaluationRule();

        if (!$regra) {
            abort(422, 'A série desta turma não tem regra de avaliação no ano. Peça à secretaria para configurar.');
        }

        if ((int) $regra->tipo_presenca !== RegraAvaliacao_Model_TipoPresenca::POR_COMPONENTE) {
            return [false, collect(), null];
        }

        $componentes = $this->componentesPermitidos($user, $turma);

        if ($componentes->isEmpty()) {
            abort(422, 'Esta turma não tem componentes curriculares para fazer a chamada.');
        }

        $componenteId = (int) ($pedido ?? $componentes->first()['id']);

        if (!$componentes->contains('id', $componenteId)) {
            abort(403, 'Você não leciona este componente nesta turma.');
        }

        return [true, $componentes, $componenteId];
    }

    /**
     * Alunos que estavam na turma no dia: enturmados até ele e, se saíram,
     * só depois dele.
     *
     * @return Collection<int, LegacyEnrollment>
     */
    private function alunosNoDia(LegacySchoolClass $turma, string $dia): Collection
    {
        return $this->enturmacoes($turma)
            ->filter(fn (LegacyEnrollment $e) => (!$e->data_enturmacao || $e->data_enturmacao->toDateString() <= $dia)
                && ($e->ativo || ($e->data_exclusao && $e->data_exclusao->toDateString() > $dia)))
            ->values();
    }

    private function motivoParaNaoLancar(User $user, LegacySchoolClass $turma, string $dia, ?int $etapa): ?string
    {
        if ($dia > now()->toDateString()) {
            return 'Não dá para fazer a chamada de um dia que ainda não chegou.';
        }

        if ($etapa === null) {
            return 'Este dia não está em nenhuma etapa do calendário da turma.';
        }

        return $this->motivoDoBloqueio($user, $turma, $etapa);
    }

    /**
     * Grava no boletim de cada aluno o total de faltas da etapa marcadas na
     * chamada. Devolve os alunos em que não deu certo, com o motivo.
     *
     * @return array<int, array{alunoId: int, nome: string, mensagem: string}>
     */
    private function atualizarBoletins(Request $request, LegacySchoolClass $turma, Collection $enturmacoes, bool $porComponente, ?int $componenteId, int $etapa): array
    {
        $totais = DailyAttendance::query()
            ->whereIn('registration_id', $enturmacoes->pluck('ref_cod_matricula'))
            ->where('discipline_id', $componenteId)
            ->where('stage', $etapa)
            ->where('present', false)
            ->groupBy('registration_id')
            ->select('registration_id', DB::raw('count(*) as faltas'))
            ->pluck('faltas', 'registration_id');

        $avisos = [];

        foreach ($enturmacoes as $e) {
            $alunoId = (int) $e->registration->ref_cod_aluno;
            $dados = [
                'turma_id' => $turma->getKey(),
                'aluno_id' => $alunoId,
                'etapa' => $etapa,
                'faltas' => (int) ($totais[$e->ref_cod_matricula] ?? 0),
            ] + ($porComponente ? ['componente_id' => $componenteId] : []);

            try {
                $resposta = $porComponente
                    ? app(FaltasComponenteController::class)($this->pedido(PostFaltaComponenteRequest::class, $request, $dados))
                    : app(FaltasGeralController::class)($this->pedido(PostFaltaGeralRequest::class, $request, $dados));
                $erro = $resposta instanceof JsonResponse && $resposta->getStatusCode() >= 400 ? (string) ($resposta->getData(true)['message'] ?? 'Erro ao gravar a falta.') : null;
            } catch (Throwable $excecao) {
                $erro = $excecao->getMessage();
            }

            if ($erro) {
                $avisos[] = ['alunoId' => $alunoId, 'nome' => $e->registration->student->person->name, 'mensagem' => $erro];
            }
        }

        return $avisos;
    }

    /**
     * @template T of FormRequest
     *
     * @param  class-string<T>  $classe
     * @return T
     */
    private function pedido(string $classe, Request $request, array $dados): FormRequest
    {
        $pedido = $classe::createFrom($request)->replace($dados);
        $pedido->setContainer(app())->setRedirector(app('redirect'));
        $pedido->validateResolved();

        return $pedido;
    }
}
