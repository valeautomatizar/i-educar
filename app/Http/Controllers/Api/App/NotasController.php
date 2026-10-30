<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Controllers\NotasController as LancamentoDeNotas;
use App\Http\Requests\PostNotasRequest;
use App\Models\LegacyDisciplineScore;
use App\Models\LegacyDisciplineScoreAverage;
use App\Models\LegacyEnrollment;
use App\Models\LegacySchoolClass;
use Illuminate\Http\Request;
use RegraAvaliacao_Model_Nota_TipoValor;

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

        $enturmacoes = $this->enturmacoes($turma);

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
}
