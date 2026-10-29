<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\LegacyGrade;
use App\Models\LegacySchoolClass;
use App\Models\LegacyStudent;
use App\Services\App\MatriculaRecusada;
use App\Services\App\NovaMatriculaService;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Nova matrícula pela interface nova: turmas com vaga para o aluno e a
 * matrícula com enturmação, com as regras da tela antiga.
 */
class MatriculaController extends Controller
{
    use EscopoEscolas;

    /** Processo "Matrícula" no controle de permissões do i-Educar. */
    private const PROCESSO_MATRICULA = 578;

    private const PROCESSO_ALUNO = 680;

    public function turmas(Request $request)
    {
        $user = $request->user();
        $this->autorizar($user);

        $dados = $request->validate([
            'aluno' => ['required', 'integer'],
            'ano' => ['nullable', 'integer', 'between:2000,2100'],
        ]);

        $ano = (int) ($dados['ano'] ?? date('Y'));
        $escolas = $this->escolasDoUsuario($user);
        $serie = (new NovaMatriculaService($user))->serieSeguinte((int) $dados['aluno']);

        // -1: a última matrícula não permite descobrir a série seguinte (sem
        // sequência cadastrada ou matrícula ainda em andamento).
        if ($serie === -1) {
            return [
                'serie' => null,
                'turmas' => [],
                'aviso' => 'Não encontramos a série seguinte deste aluno. Confira a última matrícula dele.',
            ];
        }

        $turmas = LegacySchoolClass::query()
            ->where('turma.ativo', 1)
            ->where('turma.ano', $ano)
            ->when($serie !== null, fn ($q) => $q->where('turma.ref_ref_cod_serie', $serie))
            ->when($escolas !== null, fn ($q) => $q->whereIn('turma.ref_ref_cod_escola', $escolas))
            ->with(['period', 'grade', 'school.organization', 'school.person'])
            ->withCount(['enrollments as enturmados' => fn ($q) => $q->where('ativo', 1)->whereHas('registration', fn ($r) => $r->where('dependencia', false))])
            ->orderBy('turma.nm_turma')
            ->limit(60)
            ->get()
            ->map(fn (LegacySchoolClass $turma) => [
                'id' => $turma->cod_turma,
                'nome' => $turma->nm_turma,
                'serie' => $turma->grade?->name,
                'escola' => $turma->school?->name,
                'turno' => $turma->period?->name ?? '',
                'vagas' => max(0, (int) $turma->max_aluno - (int) $turma->enturmados),
            ])
            ->filter(fn ($turma) => $turma['vagas'] > 0)
            ->values();

        return [
            'serie' => $serie ? LegacyGrade::query()->whereKey($serie)->value('nm_serie') : null,
            'turmas' => $turmas,
            'aviso' => $turmas->isEmpty() ? 'Nenhuma turma com vaga para este aluno em ' . $ano . '.' : null,
        ];
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $this->autorizar($user);

        $dados = $request->validate([
            'aluno' => ['required', 'integer'],
            'turma' => ['required', 'integer'],
            'data' => ['required', 'string'],
            'confirmarFaixaEtaria' => ['sometimes', 'boolean'],
        ], [
            'data.required' => 'Informe a data da matrícula.',
        ]);

        $data = $this->lerData($dados['data']);

        if (!$data) {
            return response()->json(['message' => 'Informe a data no formato dd/mm/aaaa.', 'precisaConfirmar' => false], 422);
        }

        $aluno = LegacyStudent::query()->find($dados['aluno']);
        $turma = LegacySchoolClass::query()->where('ativo', 1)->find($dados['turma']);

        if (!$aluno || !$turma) {
            return response()->json(['message' => $aluno ? 'Turma não encontrada.' : 'Aluno não encontrado.', 'precisaConfirmar' => false], 422);
        }

        $escolas = $this->escolasDoUsuario($user);

        if ($escolas !== null && !in_array((int) $turma->ref_ref_cod_escola, $escolas, true)) {
            abort(403, 'Esta turma não é de uma das suas escolas.');
        }

        try {
            $matricula = (new NovaMatriculaService($user))->matricular($aluno, $turma, $data, (bool) ($dados['confirmarFaixaEtaria'] ?? false));
        } catch (MatriculaRecusada $recusa) {
            return response()->json(['message' => $recusa->getMessage(), 'precisaConfirmar' => $recusa->precisaConfirmar], 422);
        }

        return response()->json([
            'matricula' => (string) $matricula->cod_matricula,
            'alunoId' => (int) $aluno->cod_aluno,
            'turma' => $turma->nm_turma,
        ], 201);
    }

    private function autorizar(User $user): void
    {
        if ($user->isLibrary() || !Gate::forUser($user)->allows('view', self::PROCESSO_ALUNO) || !Gate::forUser($user)->allows('modify', self::PROCESSO_MATRICULA)) {
            abort(403, 'Seu usuário não tem permissão para fazer matrículas.');
        }
    }

    private function lerData(string $texto): ?Carbon
    {
        foreach (['d/m/Y', 'Y-m-d'] as $formato) {
            try {
                $data = Carbon::createFromFormat('!' . $formato, $texto);
            } catch (\Throwable) {
                continue;
            }

            if ($data && $data->format($formato) === $texto) {
                return $data;
            }
        }

        return null;
    }
}
