<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Controllers\NotasController as LancamentoDeNotas;
use App\Http\Controllers\PareceresAnualGeralController;
use App\Http\Controllers\PareceresAnualPorComponenteController;
use App\Http\Controllers\PareceresPorEtapaComponenteController;
use App\Http\Controllers\PareceresPorEtapaGeralController;
use App\Http\Requests\PostNotasRequest;
use App\Http\Requests\PostPareceresAnualGeralRequest;
use App\Http\Requests\PostPareceresAnualPorComponenteRequest;
use App\Http\Requests\PostPareceresPorEtapaComponenteRequest;
use App\Http\Requests\PostPareceresPorEtapaGeralRequest;
use App\Models\LegacyDisciplineScore;
use App\Models\LegacyDisciplineScoreAverage;
use App\Models\LegacyEnrollment;
use App\Models\LegacyEvaluationRule;
use App\Models\LegacySchoolClass;
use App\Services\RemoveHtmlTagsStringService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RegraAvaliacao_Model_Nota_TipoValor;
use RegraAvaliacao_Model_TipoParecerDescritivo;

/**
 * Lançamento de notas por turma, componente e etapa para a interface nova.
 * A gravação usa o mesmo lançamento da API v2 de notas (regra de avaliação,
 * etapas anteriores, dispensa, nota máxima e exame); aqui ficam as checagens
 * de quem está logado, como na tela antiga do diário: permissão, escola,
 * vínculo do professor, período de lançamento e bloqueio do ano letivo.
 */
class NotasController extends Controller
{
    use DiarioDaTurma;

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
                ->map(fn (LegacySchoolClass $turma) => $this->turmaNaLista($turma))
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
            'etapa' => ['nullable', 'regex:/^([1-9][0-9]*|Rc)$/'],
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

        // Na turma multisseriada, só aparecem os alunos das séries que têm o
        // componente, e cada série pode ter a sua regra de avaliação.
        [$enturmacoes, $aviso] = $this->soQuemTemOComponente($turma, $this->enturmacoes($turma), $componenteId, $componentes->firstWhere('id', $componenteId)['nome'] ?? null);
        $multisseriada = $this->multisseriada($turma);
        $regraDaTurma = $turma->getEvaluationRule();
        $regras = $multisseriada
            ? $enturmacoes->map(fn (LegacyEnrollment $e) => (int) $e->registration->ref_ref_cod_serie)->unique()
                ->mapWithKeys(fn (int $serie) => [$serie => $this->regraDaSerie($turma, $serie) ?? $regraDaTurma])
            : collect();
        $regra = $regras->first(fn ($r) => $this->numerica($r)) ?? $regraDaTurma;
        $tipoNota = $this->tipoNota($regra);
        $parecer = self::PARECERES[(int) $regra->parecer_descritivo] ?? null;
        $recuperacaoFinal = $tipoNota === 'numerica' && $regra->formula_recuperacao_id !== null;

        $etapas = $this->etapas($turma);
        if ($recuperacaoFinal) {
            $etapas[] = ['numero' => 'Rc', 'nome' => 'Recuperação final', 'inicio' => null, 'fim' => null];
        }

        $etapa = $dados['etapa'] ?? $this->etapaAtual($etapas);
        $etapa = $etapa === 'Rc' ? 'Rc' : (int) $etapa;

        if ($etapa === 'Rc' && !$recuperacaoFinal) {
            abort(422, 'A regra de avaliação desta turma não tem recuperação final.');
        }

        $bloqueio = $this->motivoDoBloqueio($user, $turma, $etapa);
        $matriculas = $enturmacoes->pluck('ref_cod_matricula');

        $notas = LegacyDisciplineScore::query()
            ->join('modules.nota_aluno', 'nota_aluno.id', '=', 'nota_componente_curricular.nota_aluno_id')
            ->whereIn('nota_aluno.matricula_id', $matriculas)
            ->where('componente_curricular_id', $componenteId)
            ->where('etapa', (string) $etapa)
            ->get(['nota_aluno.matricula_id', 'nota_componente_curricular.nota_original', 'nota_componente_curricular.nota_recuperacao', 'nota_componente_curricular.nota_arredondada'])
            ->keyBy('matricula_id');

        $medias = LegacyDisciplineScoreAverage::query()
            ->join('modules.nota_aluno', 'nota_aluno.id', '=', 'nota_componente_curricular_media.nota_aluno_id')
            ->whereIn('nota_aluno.matricula_id', $matriculas)
            ->where('componente_curricular_id', $componenteId)
            ->pluck('nota_componente_curricular_media.media_arredondada', 'nota_aluno.matricula_id');

        // Nota que falta na recuperação final, para quem ficou abaixo da média no ano.
        $necessarias = $recuperacaoFinal
            ? DB::table('modules.nota_exame')
                ->whereIn('ref_cod_matricula', $matriculas)
                ->where('ref_cod_componente_curricular', $componenteId)
                ->pluck('nota_exame', 'ref_cod_matricula')
            : collect();

        $pareceres = $parecer ? $this->pareceres($matriculas->all(), $parecer, $componenteId, $etapa) : collect();
        $usaRecuperacaoParalela = $tipoNota === 'numerica'
            && (int) $regra->tipo_recuperacao_paralela === LegacyEvaluationRule::PARALLEL_REMEDIAL_PER_STAGE;

        return [
            'turma' => ['id' => $turma->cod_turma, 'nome' => $turma->nm_turma, 'turno' => $turma->period?->name],
            'multisseriada' => $multisseriada,
            'componentes' => $componentes->values(),
            'componente' => $componenteId,
            'etapas' => $etapas,
            'etapa' => $etapa,
            // Notas conceituais ainda são lançadas na tela antiga.
            'tipoNota' => $tipoNota,
            'parecer' => $parecer,
            ...$this->limites($regra, $etapa),
            'recuperacaoParalela' => $usaRecuperacaoParalela && $etapa !== 'Rc'
                ? ['media' => (float) ($regra->media_recuperacao_paralela ?: $regra->media)]
                : null,
            'podeLancar' => $bloqueio === null,
            'motivo' => $bloqueio,
            'aviso' => $aviso,
            'alunos' => $enturmacoes->map(function (LegacyEnrollment $e) use ($notas, $medias, $regras, $regra, $etapa, $necessarias, $pareceres) {
                $regraDoAluno = $regras->get((int) $e->registration->ref_ref_cod_serie, $regra);
                $diferente = (int) $regraDoAluno->getKey() !== (int) $regra->getKey();
                $nota = $notas[$e->ref_cod_matricula] ?? null;

                return [
                    'alunoId' => (int) $e->registration->ref_cod_aluno,
                    'matriculaId' => (int) $e->ref_cod_matricula,
                    'nome' => $e->registration->student->person->name,
                    'serie' => $e->registration->grade?->name,
                    'nota' => $this->numero($nota?->nota_original),
                    'recuperacao' => $this->numero($nota?->nota_recuperacao),
                    // Nota que fica no boletim: a maior entre a da etapa e a da recuperação, já arredondada.
                    'notaFinal' => $this->numero($nota?->nota_arredondada),
                    'media' => $this->numero($medias[$e->ref_cod_matricula] ?? null),
                    'notaNecessaria' => $this->numero($necessarias[$e->ref_cod_matricula] ?? null),
                    'parecer' => $pareceres[$e->ref_cod_matricula] ?? null,
                    'saiu' => $e->ativo ? null : 'Transferido',
                    // Só quando a série do aluno usa outra regra que a da tela.
                    'limites' => $diferente && $this->numerica($regraDoAluno) ? $this->limites($regraDoAluno, $etapa) : null,
                    'bloqueio' => $diferente && !$this->numerica($regraDoAluno) ? $this->porQueNaoLanca($regraDoAluno) : null,
                ];
            })->values(),
        ];
    }

    private function numerica(?LegacyEvaluationRule $regra): bool
    {
        return $regra !== null && (int) $regra->tipo_nota === RegraAvaliacao_Model_Nota_TipoValor::NUMERICA;
    }

    /**
     * Na recuperação final valem a nota máxima do exame e a média da recuperação.
     *
     * @return array{notaMinima: float, notaMaxima: float, mediaAprovacao: float, casasDecimais: int}
     */
    private function limites(LegacyEvaluationRule $regra, int|string $etapa = 1): array
    {
        $final = $etapa === 'Rc';

        return [
            'notaMinima' => (float) $regra->nota_minima_geral,
            'notaMaxima' => (float) ($final ? $regra->nota_maxima_exame_final : $regra->nota_maxima_geral),
            'mediaAprovacao' => (float) ($final ? $regra->media_recuperacao : $regra->media),
            'casasDecimais' => (int) $regra->qtd_casas_decimais,
        ];
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $this->autorizar($user);

        $dados = $request->validate([
            'turma' => ['required', 'integer'],
            'componente' => ['required', 'integer'],
            'etapa' => ['required', 'regex:/^([1-9][0-9]*|Rc)$/'],
            'aluno' => ['required', 'integer'],
            'nota' => ['required', 'numeric'],
            'recuperacao' => ['nullable', 'numeric'],
        ], [
            'nota.required' => 'Informe a nota.',
            'nota.numeric' => 'A nota precisa ser um número.',
            'recuperacao.numeric' => 'A nota da recuperação precisa ser um número.',
        ]);

        $turma = $this->turmaPermitida($user, (int) $dados['turma']);

        if (!$this->componentesPermitidos($user, $turma)->contains('id', (int) $dados['componente'])) {
            abort(403, 'Você não leciona este componente nesta turma.');
        }

        if ($motivo = $this->motivoDoBloqueio($user, $turma, $dados['etapa'])) {
            return response()->json(['message' => $motivo], 422);
        }

        $lancamento = [
            'turma_id' => (int) $dados['turma'],
            'componente_id' => (int) $dados['componente'],
            'etapa' => (string) $dados['etapa'],
            'aluno_id' => (int) $dados['aluno'],
            'nota' => (string) $dados['nota'],
        ];

        if ($dados['etapa'] !== 'Rc') {
            // Sem o campo, mantém a recuperação já lançada; o lançamento apagaria.
            $recuperacao = $request->has('recuperacao')
                ? $dados['recuperacao']
                : $this->recuperacaoLancada($turma, (int) $dados['aluno'], (int) $dados['componente'], $dados['etapa']);

            if ($recuperacao !== null && $recuperacao !== '') {
                $lancamento['recuperacao'] = (string) $recuperacao;
            }
        }

        // Mesmo lançamento da API v2 de notas, com as mesmas validações.
        $pedido = PostNotasRequest::createFrom($request)->replace($lancamento);
        $pedido->setContainer(app())->setRedirector(app('redirect'));
        $pedido->validateResolved();

        return app(LancamentoDeNotas::class)($pedido);
    }

    /**
     * Parecer descritivo do aluno: por trimestre (geral ou do componente) ou do ano.
     */
    public function parecer(Request $request)
    {
        $user = $request->user();
        $this->autorizar($user);

        $dados = $request->validate([
            'turma' => ['required', 'integer'],
            'componente' => ['nullable', 'integer'],
            'etapa' => ['nullable', 'regex:/^([1-9][0-9]*|Rc)$/'],
            'aluno' => ['required', 'integer'],
            'parecer' => ['present', 'nullable', 'string', 'max:10000'],
        ], [
            'parecer.max' => 'O parecer está muito longo.',
        ]);

        $turma = $this->turmaPermitida($user, (int) $dados['turma']);
        $tipo = self::PARECERES[(int) $turma->getEvaluationRule()->parecer_descritivo] ?? null;

        if ($tipo === null) {
            return response()->json(['message' => 'A regra de avaliação desta turma não usa parecer.'], 422);
        }

        $porComponente = str_ends_with($tipo, 'componente');
        $anual = str_starts_with($tipo, 'anual');

        if ($porComponente && !$this->componentesPermitidos($user, $turma)->contains('id', (int) ($dados['componente'] ?? 0))) {
            abort(403, 'Você não leciona este componente nesta turma.');
        }

        if (!$anual && empty($dados['etapa'])) {
            return response()->json(['message' => 'Informe a etapa do parecer.'], 422);
        }

        if ($motivo = $this->motivoDoBloqueio($user, $turma, $anual ? 'An' : $dados['etapa'])) {
            return response()->json(['message' => $motivo], 422);
        }

        $lancamento = array_filter([
            'turma_id' => (int) $dados['turma'],
            'aluno_id' => (int) $dados['aluno'],
            'componente_id' => $porComponente ? (int) $dados['componente'] : null,
            'etapa' => $anual ? null : (string) $dados['etapa'],
        ], fn ($valor) => $valor !== null) + ['parecer' => (string) ($dados['parecer'] ?? '')];

        [$classeDoPedido, $classeDoLancamento] = match ($tipo) {
            'etapa_geral' => [PostPareceresPorEtapaGeralRequest::class, PareceresPorEtapaGeralController::class],
            'etapa_componente' => [PostPareceresPorEtapaComponenteRequest::class, PareceresPorEtapaComponenteController::class],
            'anual_geral' => [PostPareceresAnualGeralRequest::class, PareceresAnualGeralController::class],
            'anual_componente' => [PostPareceresAnualPorComponenteRequest::class, PareceresAnualPorComponenteController::class],
        };

        // Mesmo lançamento da API v2 de pareceres, com as mesmas validações.
        $pedido = $classeDoPedido::createFrom($request)->replace($lancamento);
        $pedido->setContainer(app())->setRedirector(app('redirect'));
        $pedido->validateResolved();

        return app($classeDoLancamento)($pedido, app(RemoveHtmlTagsStringService::class));
    }

    private const PARECERES = [
        RegraAvaliacao_Model_TipoParecerDescritivo::ETAPA_GERAL => 'etapa_geral',
        RegraAvaliacao_Model_TipoParecerDescritivo::ETAPA_COMPONENTE => 'etapa_componente',
        RegraAvaliacao_Model_TipoParecerDescritivo::ANUAL_GERAL => 'anual_geral',
        RegraAvaliacao_Model_TipoParecerDescritivo::ANUAL_COMPONENTE => 'anual_componente',
    ];

    private function porQueNaoLanca(LegacyEvaluationRule $regra): string
    {
        return $this->tipoNota($regra) === 'nenhuma'
            ? 'A série deste aluno é avaliada por parecer descritivo. Lance o parecer pela tela antiga.'
            : 'A série deste aluno usa nota por conceito. Lance pela tela antiga.';
    }

    private function tipoNota(LegacyEvaluationRule $regra): string
    {
        return match ((int) $regra->tipo_nota) {
            RegraAvaliacao_Model_Nota_TipoValor::NUMERICA => 'numerica',
            RegraAvaliacao_Model_Nota_TipoValor::NENHUM => 'nenhuma',
            default => 'outra',
        };
    }

    private function numero($valor): ?float
    {
        return is_numeric($valor) ? (float) $valor : null;
    }

    /**
     * @param array<int> $matriculas
     * @return Collection<int, string> matrícula => parecer
     */
    private function pareceres(array $matriculas, string $tipo, int $componente, int|string $etapa): Collection
    {
        $tabela = str_ends_with($tipo, 'componente') ? 'modules.parecer_componente_curricular' : 'modules.parecer_geral';

        return DB::table($tabela)
            ->join('modules.parecer_aluno', 'parecer_aluno.id', '=', "{$tabela}.parecer_aluno_id")
            ->whereIn('parecer_aluno.matricula_id', $matriculas)
            ->where("{$tabela}.etapa", str_starts_with($tipo, 'anual') ? 'An' : (string) $etapa)
            ->when(str_ends_with($tipo, 'componente'), fn ($q) => $q->where("{$tabela}.componente_curricular_id", $componente))
            ->pluck("{$tabela}.parecer", 'parecer_aluno.matricula_id');
    }

    private function recuperacaoLancada(LegacySchoolClass $turma, int $aluno, int $componente, string $etapa): ?string
    {
        $valor = LegacyDisciplineScore::query()
            ->join('modules.nota_aluno', 'nota_aluno.id', '=', 'nota_componente_curricular.nota_aluno_id')
            ->join('pmieducar.matricula_turma', 'matricula_turma.ref_cod_matricula', '=', 'nota_aluno.matricula_id')
            ->join('pmieducar.matricula', 'matricula.cod_matricula', '=', 'nota_aluno.matricula_id')
            ->where('matricula_turma.ref_cod_turma', $turma->getKey())
            ->where('matricula.ref_cod_aluno', $aluno)
            ->where('matricula.ativo', 1)
            ->where('componente_curricular_id', $componente)
            ->where('etapa', $etapa)
            ->value('nota_componente_curricular.nota_recuperacao');

        return is_numeric($valor) ? (string) $valor : null;
    }
}
