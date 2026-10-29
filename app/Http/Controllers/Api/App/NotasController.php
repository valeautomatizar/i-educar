<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Controllers\NotasController as LancamentoDeNotas;
use App\Http\Requests\PostNotasRequest;
use App\Models\LegacyDiscipline;
use App\Models\LegacyDisciplineScore;
use App\Models\LegacyDisciplineScoreAverage;
use App\Models\LegacyEnrollment;
use App\Models\LegacySchoolAcademicYear;
use App\Models\LegacySchoolClass;
use App\Models\LegacySchoolClassTeacher;
use App\Models\LegacySchoolYearLock;
use App\Process;
use App\Services\DiarioControllerService;
use App\Services\ReleasePeriodService;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use RegraAvaliacao_Model_Nota_TipoValor;
use Throwable;

/**
 * Lançamento de notas por turma, componente e etapa para a interface nova.
 * A gravação usa o mesmo lançamento da API v2 de notas (regra de avaliação,
 * etapas anteriores, dispensa, nota máxima e exame); aqui ficam as checagens
 * de quem está logado, como na tela antiga do diário: permissão, escola,
 * vínculo do professor, período de lançamento e bloqueio do ano letivo.
 */
class NotasController extends Controller
{
    use EscopoEscolas;

    /** Processo "Lançamento de notas/faltas" no controle de permissões. */
    private const PROCESSO_DIARIO = 642;

    public function turmas(Request $request)
    {
        $user = $request->user();
        $this->autorizar($user);

        $ano = (int) ($request->validate(['ano' => ['nullable', 'integer', 'between:2000,2100']])['ano'] ?? date('Y'));
        $doProfessor = $this->vinculosDoProfessor($user, $ano);
        $escolas = $this->escolasDoUsuario($user);

        return [
            'ano' => $ano,
            'turmas' => LegacySchoolClass::query()
                ->where('ativo', 1)
                ->where('ano', $ano)
                ->when($doProfessor !== null, fn ($q) => $q->whereIn('cod_turma', $doProfessor->keys()))
                ->when($escolas !== null, fn ($q) => $q->whereIn('ref_ref_cod_escola', $escolas))
                ->with(['period', 'grade', 'school.organization', 'school.person'])
                ->orderBy('nm_turma')
                ->limit(100)
                ->get()
                ->map(fn (LegacySchoolClass $turma) => [
                    'id' => $turma->cod_turma,
                    'nome' => $turma->nm_turma,
                    'serie' => $turma->grade?->name,
                    'escola' => $turma->school?->name,
                    'turno' => $turma->period?->name,
                ])
                ->values(),
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $this->autorizar($user);

        $dados = $request->validate([
            'turma' => ['required', 'integer'],
            'componente' => ['nullable', 'integer'],
            'etapa' => ['nullable', 'integer', 'min:1'],
        ]);

        $turma = $this->turmaPermitida($user, (int) $dados['turma']);
        $componentes = $this->componentesPermitidos($user, $turma);

        if ($componentes->isEmpty()) {
            abort(422, 'Esta turma não tem componentes curriculares para lançar notas.');
        }

        $componenteId = (int) ($dados['componente'] ?? $componentes->first()['id']);

        if (!$componentes->contains('id', $componenteId)) {
            abort(403, 'Você não leciona este componente nesta turma.');
        }

        $etapas = $this->etapas($turma);
        $etapa = (int) ($dados['etapa'] ?? $this->etapaAtual($etapas));
        $regra = $turma->getEvaluationRule();
        $bloqueio = $this->motivoDoBloqueio($user, $turma, $etapa);

        $enturmacoes = LegacyEnrollment::query()
            ->where('ref_cod_turma', $turma->getKey())
            ->where(fn ($q) => $q->where('ativo', 1)->orWhere('transferido', true))
            ->whereHas('registration', fn ($q) => $q->where('ativo', 1)->whereHas('student', fn ($s) => $s->where('ativo', 1)))
            ->with('registration.student.person')
            ->get()
            ->sortBy(fn (LegacyEnrollment $e) => [$e->sequencial_fechamento, $e->registration->student->person->name]);

        $matriculas = $enturmacoes->pluck('ref_cod_matricula');

        $notas = LegacyDisciplineScore::query()
            ->join('modules.nota_aluno', 'nota_aluno.id', '=', 'nota_componente_curricular.nota_aluno_id')
            ->whereIn('nota_aluno.matricula_id', $matriculas)
            ->where('componente_curricular_id', $componenteId)
            ->where('etapa', (string) $etapa)
            ->pluck('nota_componente_curricular.nota_original', 'nota_aluno.matricula_id');

        $medias = LegacyDisciplineScoreAverage::query()
            ->join('modules.nota_aluno', 'nota_aluno.id', '=', 'nota_componente_curricular_media.nota_aluno_id')
            ->whereIn('nota_aluno.matricula_id', $matriculas)
            ->where('componente_curricular_id', $componenteId)
            ->pluck('nota_componente_curricular_media.media_arredondada', 'nota_aluno.matricula_id');

        return [
            'turma' => ['id' => $turma->cod_turma, 'nome' => $turma->nm_turma, 'turno' => $turma->period?->name],
            'componentes' => $componentes->values(),
            'componente' => $componenteId,
            'etapas' => $etapas,
            'etapa' => $etapa,
            // Notas conceituais ainda são lançadas na tela antiga.
            'tipoNota' => (int) $regra->tipo_nota === RegraAvaliacao_Model_Nota_TipoValor::NUMERICA ? 'numerica' : 'outra',
            'notaMinima' => (float) $regra->nota_minima_geral,
            'notaMaxima' => (float) $regra->nota_maxima_geral,
            'mediaAprovacao' => (float) $regra->media,
            'casasDecimais' => (int) $regra->qtd_casas_decimais,
            'podeLancar' => $bloqueio === null,
            'motivo' => $bloqueio,
            'alunos' => $enturmacoes->map(fn (LegacyEnrollment $e) => [
                'alunoId' => (int) $e->registration->ref_cod_aluno,
                'matriculaId' => (int) $e->ref_cod_matricula,
                'nome' => $e->registration->student->person->name,
                'nota' => is_numeric($notas[$e->ref_cod_matricula] ?? null) ? (float) $notas[$e->ref_cod_matricula] : null,
                'media' => is_numeric($medias[$e->ref_cod_matricula] ?? null) ? (float) $medias[$e->ref_cod_matricula] : null,
                'saiu' => $e->ativo ? null : 'Transferido',
            ])->values(),
        ];
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $this->autorizar($user);

        $dados = $request->validate([
            'turma' => ['required', 'integer'],
            'componente' => ['required', 'integer'],
            'etapa' => ['required', 'integer', 'min:1'],
            'aluno' => ['required', 'integer'],
            'nota' => ['required', 'numeric'],
        ], [
            'nota.required' => 'Informe a nota.',
            'nota.numeric' => 'A nota precisa ser um número.',
        ]);

        $turma = $this->turmaPermitida($user, (int) $dados['turma']);

        if (!$this->componentesPermitidos($user, $turma)->contains('id', (int) $dados['componente'])) {
            abort(403, 'Você não leciona este componente nesta turma.');
        }

        if ($motivo = $this->motivoDoBloqueio($user, $turma, (int) $dados['etapa'])) {
            return response()->json(['message' => $motivo], 422);
        }

        // Mesmo lançamento da API v2 de notas, com as mesmas validações.
        $lancamento = PostNotasRequest::createFrom($request)->replace([
            'turma_id' => (int) $dados['turma'],
            'componente_id' => (int) $dados['componente'],
            'etapa' => (string) $dados['etapa'],
            'aluno_id' => (int) $dados['aluno'],
            'nota' => (string) $dados['nota'],
        ]);
        $lancamento->setContainer(app())->setRedirector(app('redirect'));
        $lancamento->validateResolved();

        return app(LancamentoDeNotas::class)($lancamento);
    }

    private function autorizar(User $user): void
    {
        if ($user->isLibrary() || !Gate::forUser($user)->allows('modify', self::PROCESSO_DIARIO)) {
            abort(403, 'Seu usuário não tem permissão para lançar notas.');
        }
    }

    /**
     * Turmas e componentes do professor no ano (componentes vazios = todos).
     * Null quando o usuário não é professor com vínculo, e vale o escopo da escola.
     *
     * @return Collection<int, array<int, int>>|null
     */
    private function vinculosDoProfessor(User $user, int $ano): ?Collection
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

    private function turmaPermitida(User $user, int $id): LegacySchoolClass
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
    private function componentesPermitidos(User $user, LegacySchoolClass $turma): Collection
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
    private function etapas(LegacySchoolClass $turma): array
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

    private function etapaAtual(array $etapas): int
    {
        $hoje = date('Y-m-d');

        foreach ($etapas as $etapa) {
            if ($etapa['inicio'] && $etapa['fim'] && $etapa['inicio'] <= $hoje && $hoje <= $etapa['fim']) {
                return $etapa['numero'];
            }
        }

        return $etapas[0]['numero'] ?? 1;
    }

    /**
     * Por que a pessoa não pode lançar agora, ou null se pode. Mesmas regras
     * do diário da tela antiga.
     */
    private function motivoDoBloqueio(User $user, LegacySchoolClass $turma, int $etapa): ?string
    {
        $ano = (int) $turma->ano;
        $escola = (int) $turma->ref_ref_cod_escola;

        $anoLetivo = LegacySchoolAcademicYear::query()->whereSchool($escola)->whereYearEq($ano)->first(['ativo', 'andamento']);

        if ($turma->school?->bloquear_lancamento_diario_anos_letivos_encerrados == 1 && $anoLetivo && $anoLetivo->ativo == 1 && $anoLetivo->andamento == 2) {
            return "O ano letivo '{$ano}' está encerrado, esta escola está configurada para não permitir alterar o diário de anos letivos encerrados.";
        }

        $bloqueio = LegacySchoolYearLock::query()->whereInstitution((int) $turma->ref_cod_instituicao)->whereYear($ano)->first(['data_inicio', 'data_fim']);

        if ($bloqueio && (date('Y-m-d') < substr((string) $bloqueio->data_inicio, 0, 10) || date('Y-m-d') > substr((string) $bloqueio->data_fim, 0, 10))) {
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
}
