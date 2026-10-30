<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\ClassNote;
use App\Models\LegacyEnrollment;
use App\Models\LegacySchoolClass;
use App\Models\LessonRecord;
use App\User;
use Illuminate\Http\Request;

/**
 * Diário do professor na interface nova: o que foi ensinado em cada aula
 * ("O que ensinei hoje") e anotações sobre a turma e sobre os alunos.
 */
class DiarioController extends Controller
{
    use DiarioDaTurma;

    /** Tamanho máximo dos textos, para caber numa tela de celular sem susto. */
    private const MAX_TEXTO = 5000;

    public function aula(Request $request)
    {
        $user = $request->user();
        $this->autorizar($user);

        $dados = $request->validate([
            'turma' => ['required', 'integer'],
            'componente' => ['nullable', 'integer'],
            'data' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $turma = $this->turmaPermitida($user, (int) $dados['turma']);
        $componentes = $this->componentesPermitidos($user, $turma);
        $componenteId = $this->componenteEscolhido($componentes, $dados['componente'] ?? null);
        $data = $dados['data'] ?? now()->format('Y-m-d');

        $doProfessor = LessonRecord::query()
            ->where('school_class_id', $turma->getKey())
            ->where('discipline_id', $componenteId)
            ->where('user_id', $user->getKey());

        $aula = (clone $doProfessor)->whereDate('date', $data)->first();
        $ultima = (clone $doProfessor)->whereDate('date', '<', $data)->orderByDesc('date')->first();

        return [
            'turma' => $this->dadosDaTurma($turma),
            'componentes' => $componentes->values(),
            'componente' => $componenteId,
            'data' => $data,
            'aula' => $aula ? $this->dadosDaAula($aula) : null,
            'ultima' => $ultima ? $this->dadosDaAula($ultima) : null,
        ];
    }

    public function salvarAula(Request $request)
    {
        $user = $request->user();
        $this->autorizar($user);

        $dados = $request->validate([
            'turma' => ['required', 'integer'],
            'componente' => ['nullable', 'integer'],
            'data' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'conteudo' => ['required', 'string', 'max:' . self::MAX_TEXTO],
        ], [
            'data.before_or_equal' => 'Não é possível registrar uma aula que ainda não aconteceu.',
            'conteudo.required' => 'Escreva o que você ensinou nesta aula.',
            'conteudo.max' => 'O texto está muito longo. Use no máximo ' . self::MAX_TEXTO . ' letras.',
        ]);

        $turma = $this->turmaPermitida($user, (int) $dados['turma']);
        $componenteId = $this->componenteEscolhido($this->componentesPermitidos($user, $turma), $dados['componente'] ?? null);

        $aula = LessonRecord::query()->updateOrCreate([
            'school_class_id' => $turma->getKey(),
            'discipline_id' => $componenteId,
            'user_id' => $user->getKey(),
            'date' => $dados['data'],
        ], [
            'content' => trim($dados['conteudo']),
        ]);

        return response()->json(['aula' => $this->dadosDaAula($aula)], $aula->wasRecentlyCreated ? 201 : 200);
    }

    public function anotacoes(Request $request)
    {
        $user = $request->user();
        $this->autorizar($user);

        $dados = $request->validate([
            'turma' => ['required', 'integer'],
            'aluno' => ['nullable', 'integer'],
        ]);

        $turma = $this->turmaPermitida($user, (int) $dados['turma']);

        $anotacoes = ClassNote::query()
            ->where('school_class_id', $turma->getKey())
            ->when(isset($dados['aluno']), fn ($q) => $q->where('student_id', (int) $dados['aluno']))
            ->with(['student.person', 'user.person'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return [
            'turma' => $this->dadosDaTurma($turma),
            'alunos' => $this->alunosDaTurma($turma),
            'anotacoes' => $anotacoes->map(fn (ClassNote $a) => $this->dadosDaAnotacao($a, $user))->values(),
        ];
    }

    public function salvarAnotacao(Request $request)
    {
        $user = $request->user();
        $this->autorizar($user);

        $dados = $request->validate([
            'turma' => ['required', 'integer'],
            'aluno' => ['nullable', 'integer'],
            'data' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'texto' => ['required', 'string', 'max:' . self::MAX_TEXTO],
        ], [
            'data.before_or_equal' => 'A data da anotação não pode ser no futuro.',
            'texto.required' => 'Escreva a anotação.',
            'texto.max' => 'O texto está muito longo. Use no máximo ' . self::MAX_TEXTO . ' letras.',
        ]);

        $turma = $this->turmaPermitida($user, (int) $dados['turma']);
        $alunoId = isset($dados['aluno']) ? (int) $dados['aluno'] : null;

        if ($alunoId !== null && !$this->alunoEstaNaTurma($turma, $alunoId)) {
            abort(422, 'Este aluno não está matriculado nesta turma.');
        }

        $anotacao = ClassNote::query()->create([
            'school_class_id' => $turma->getKey(),
            'student_id' => $alunoId,
            'user_id' => $user->getKey(),
            'date' => $dados['data'] ?? now()->format('Y-m-d'),
            'text' => trim($dados['texto']),
        ]);

        return response()->json(['anotacao' => $this->dadosDaAnotacao($anotacao->load(['student.person', 'user.person']), $user)], 201);
    }

    public function apagarAnotacao(Request $request, int $anotacao)
    {
        $user = $request->user();
        $this->autorizar($user);

        $registro = ClassNote::query()->find($anotacao);

        if (!$registro) {
            abort(404, 'Anotação não encontrada.');
        }

        $this->turmaPermitida($user, (int) $registro->school_class_id);

        if (!$this->podeApagar($registro, $user)) {
            abort(403, 'Só quem escreveu a anotação pode apagá-la.');
        }

        $registro->delete();

        return ['apagada' => true];
    }

    /**
     * Componente pedido, se o usuário puder usá-lo; sem pedido, o primeiro
     * permitido. Turma sem componentes (educação infantil) usa null.
     */
    private function componenteEscolhido($componentes, $pedido): ?int
    {
        if ($pedido === null) {
            return $componentes->first()['id'] ?? null;
        }

        if (!$componentes->contains('id', (int) $pedido)) {
            abort(403, 'Você não leciona este componente nesta turma.');
        }

        return (int) $pedido;
    }

    private function alunoEstaNaTurma(LegacySchoolClass $turma, int $alunoId): bool
    {
        return LegacyEnrollment::query()
            ->where('ref_cod_turma', $turma->getKey())
            ->where('ativo', 1)
            ->whereHas('registration', fn ($q) => $q->where('ativo', 1)->where('ref_cod_aluno', $alunoId))
            ->exists();
    }

    /**
     * @return array<int, array{id: int, nome: string}>
     */
    private function alunosDaTurma(LegacySchoolClass $turma): array
    {
        return LegacyEnrollment::query()
            ->where('ref_cod_turma', $turma->getKey())
            ->where('ativo', 1)
            ->whereHas('registration', fn ($q) => $q->where('ativo', 1)->whereHas('student', fn ($s) => $s->where('ativo', 1)))
            ->with('registration.student.person')
            ->get()
            ->map(fn (LegacyEnrollment $e) => [
                'id' => (int) $e->registration->ref_cod_aluno,
                'nome' => (string) $e->registration->student->person->name,
            ])
            ->sortBy('nome')
            ->values()
            ->all();
    }

    private function podeApagar(ClassNote $anotacao, User $user): bool
    {
        return (int) $anotacao->user_id === (int) $user->getKey() || $user->isAdmin();
    }

    private function dadosDaTurma(LegacySchoolClass $turma): array
    {
        return [
            'id' => (int) $turma->cod_turma,
            'nome' => $turma->nm_turma,
            'escola' => $turma->school?->name,
            'turno' => $turma->period?->name,
        ];
    }

    private function dadosDaAula(LessonRecord $aula): array
    {
        return [
            'id' => $aula->id,
            'data' => $aula->date->format('Y-m-d'),
            'conteudo' => $aula->content,
            'atualizadoEm' => $aula->updated_at?->toIso8601String(),
        ];
    }

    private function dadosDaAnotacao(ClassNote $anotacao, User $user): array
    {
        return [
            'id' => $anotacao->id,
            'data' => $anotacao->date->format('Y-m-d'),
            'texto' => $anotacao->text,
            'aluno' => $anotacao->student_id ? ['id' => (int) $anotacao->student_id, 'nome' => $anotacao->student?->person?->name] : null,
            'autor' => $anotacao->user?->person?->name,
            'podeApagar' => $this->podeApagar($anotacao, $user),
        ];
    }
}
