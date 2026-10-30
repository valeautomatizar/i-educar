<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\BnccSkill;
use App\Models\ClassNote;
use App\Models\LegacyEnrollment;
use App\Models\LegacySchoolClass;
use App\Models\LessonRecord;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Diário do professor na interface nova: o que foi ensinado em cada aula
 * ("O que ensinei hoje") e anotações sobre a turma e sobre os alunos.
 */
class DiarioController extends Controller
{
    use DiarioDaTurma;

    /** Tamanho máximo dos textos, para caber numa tela de celular sem susto. */
    private const MAX_TEXTO = 5000;

    /** Quantas habilidades da BNCC uma aula pode ter. */
    private const MAX_HABILIDADES = 10;

    /** Quantas habilidades a busca devolve. */
    private const SUGESTOES = 8;

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

        $aula = (clone $doProfessor)->whereDate('date', $data)->with('skills')->first();
        $ultima = (clone $doProfessor)->whereDate('date', '<', $data)->orderByDesc('date')->with('skills')->first();

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
            'habilidades' => ['nullable', 'array', 'max:' . self::MAX_HABILIDADES],
            'habilidades.*' => ['string', 'distinct', Rule::exists('bncc_skills', 'code')],
        ], [
            'habilidades.max' => 'Escolha no máximo ' . self::MAX_HABILIDADES . ' habilidades.',
            'habilidades.*.exists' => 'Habilidade da BNCC não encontrada.',
            'data.before_or_equal' => 'Não é possível registrar uma aula que ainda não aconteceu.',
            'conteudo.required' => 'Escreva o que você ensinou nesta aula.',
            'conteudo.max' => 'O texto está muito longo. Use no máximo ' . self::MAX_TEXTO . ' letras.',
        ]);

        $turma = $this->turmaPermitida($user, (int) $dados['turma']);
        $componenteId = $this->componenteEscolhido($this->componentesPermitidos($user, $turma), $dados['componente'] ?? null);

        $aula = DB::transaction(function () use ($turma, $componenteId, $user, $dados) {
            $aula = LessonRecord::query()->updateOrCreate([
                'school_class_id' => $turma->getKey(),
                'discipline_id' => $componenteId,
                'user_id' => $user->getKey(),
                'date' => $dados['data'],
            ], [
                'content' => trim($dados['conteudo']),
            ]);

            // Sem o campo, as habilidades já ligadas à aula ficam como estão.
            if (array_key_exists('habilidades', $dados)) {
                $aula->skills()->sync(collect($dados['habilidades'] ?? [])->values()->mapWithKeys(fn ($codigo, $posicao) => [$codigo => ['position' => $posicao]])->all());
            }

            return $aula;
        });

        return response()->json(['aula' => $this->dadosDaAula($aula->load('skills'))], $aula->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Habilidades da BNCC para a aula: as que combinam com as palavras
     * (o que o professor escreveu, ou uma busca), primeiro as do componente
     * e do ano da turma.
     */
    public function habilidades(Request $request)
    {
        $user = $request->user();
        $this->autorizar($user);

        $dados = $request->validate([
            'turma' => ['required', 'integer'],
            'componente' => ['nullable', 'integer'],
            'busca' => ['nullable', 'string', 'max:' . self::MAX_TEXTO],
        ]);

        $turma = $this->turmaPermitida($user, (int) $dados['turma']);
        $componentes = $this->componentesPermitidos($user, $turma);
        $componenteId = $this->componenteEscolhido($componentes, $dados['componente'] ?? null);
        $nomeComponente = $componentes->firstWhere('id', $componenteId)['nome'] ?? null;

        $etapa = $this->etapaDaBncc($turma);
        $ano = $etapa === 'EF' ? $this->anoDaTurma($turma) : null;
        $busca = trim($dados['busca'] ?? '');

        // Quem já sabe o código (ex.: EF05MA03) acha a habilidade direto.
        $habilidades = preg_match('/^E[FI]\d{2}[A-Z0-9]*$/i', $busca)
            ? BnccSkill::query()->where('code', 'like', strtoupper($busca) . '%')->orderBy('code')->limit(self::SUGESTOES)->get()
            : BnccSkill::query()
                ->sugeridas(BnccSkill::palavras($busca), $this->componenteDaBncc($nomeComponente), $ano, $etapa)
                ->limit(self::SUGESTOES)
                ->get();

        return [
            'etapa' => $etapa,
            'ano' => $ano,
            'componente' => $this->componenteDaBncc($nomeComponente),
            'habilidades' => $habilidades->map(fn (BnccSkill $h) => $this->dadosDaHabilidade($h))->values(),
        ];
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
            'habilidades' => $aula->skills->map(fn (BnccSkill $h) => $this->dadosDaHabilidade($h))->values(),
            'atualizadoEm' => $aula->updated_at?->toIso8601String(),
        ];
    }

    private function dadosDaHabilidade(BnccSkill $habilidade): array
    {
        return [
            'codigo' => $habilidade->code,
            'texto' => $habilidade->description,
            'componente' => $habilidade->component,
        ];
    }

    /** EI para turma de educação infantil, EF quando dá para saber o ano, ou null. */
    private function etapaDaBncc(LegacySchoolClass $turma): ?string
    {
        $nomes = BnccSkill::normalizar(($turma->course?->name ?? '') . ' ' . ($turma->grade?->name ?? ''));

        if (preg_match('/infantil|creche|pre[ -]?escola|\bpre\b|maternal|bercario/', $nomes)) {
            return 'EI';
        }

        return $this->anoDaTurma($turma) ? 'EF' : null;
    }

    /** Ano do Ensino Fundamental pelo nome da série ("5º ano", "5 ano", "Quinto ano"). */
    private function anoDaTurma(LegacySchoolClass $turma): ?int
    {
        $serie = BnccSkill::normalizar($turma->grade?->name ?? '');

        if (preg_match('/\b([1-9])\s*(o|a)?\s*ano\b/', $serie, $achado)) {
            return (int) $achado[1];
        }

        $ordinais = ['primeiro', 'segundo', 'terceiro', 'quarto', 'quinto', 'sexto', 'setimo', 'oitavo', 'nono'];

        foreach ($ordinais as $posicao => $ordinal) {
            if (preg_match("/\\b{$ordinal}\\s+ano\\b/", $serie)) {
                return $posicao + 1;
            }
        }

        return null;
    }

    /** Nome do componente da BNCC que corresponde ao componente do i-Educar. */
    private function componenteDaBncc(?string $nome): ?string
    {
        $nome = BnccSkill::normalizar($nome ?? '');

        return match (true) {
            $nome === '' => null,
            str_contains($nome, 'portugu') => 'Língua Portuguesa',
            str_contains($nome, 'ingl') => 'Língua Inglesa',
            str_contains($nome, 'matem') => 'Matemática',
            str_contains($nome, 'cienc') => 'Ciências',
            str_contains($nome, 'hist') => 'História',
            str_contains($nome, 'geog') => 'Geografia',
            str_contains($nome, 'fisica') => 'Educação Física',
            str_contains($nome, 'relig') => 'Ensino Religioso',
            str_contains($nome, 'arte') => 'Arte',
            default => null,
        };
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
