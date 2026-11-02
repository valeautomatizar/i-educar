<?php

use App\Models\LegacyEnrollment;
use App\Models\LegacyRegistration;
use App\Models\LegacySchoolClass;
use App\Models\LegacySchoolClassGrade;
use App\Models\RegistrationStatus;
use Database\Factories\LegacyGradeSequenceFactory;
use Database\Factories\LegacyUserFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Request\Support\EnrollmentScenario;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

/**
 * Turma multisseriada "Multi 1º e 2º ano" com Ana (Primeiro Ano) e Bruno
 * (Segundo Ano). Matemática é das duas séries; Ciências só do Segundo Ano.
 */
beforeEach(function () {
    $this->travelTo('2026-03-20 10:00:00');
    $this->user = LegacyUserFactory::new()->admin()->create();
    $this->scenario = EnrollmentScenario::for($this->user, 2026);
    $this->segundoAno = $this->scenario->grade('2º ano', ['descricao' => null, 'etapa_curso' => 2]);
    $this->scenario->grade->update(['nm_serie' => '1º ano', 'descricao' => null, 'etapa_curso' => 1]);

    $regra = [
        'tipo_nota' => RegraAvaliacao_Model_Nota_TipoValor::NUMERICA,
        'tipo_presenca' => RegraAvaliacao_Model_TipoPresenca::GERAL,
        'parecer_descritivo' => RegraAvaliacao_Model_TipoParecerDescritivo::NENHUM,
        'tipo_recuperacao_paralela' => RegraAvaliacao_Model_TipoRecuperacaoParalela::NAO_USAR,
        'media' => 6,
        'nota_maxima_geral' => 10,
        'nota_minima_geral' => 0,
    ];
    $this->scenario->evaluationRule($regra);
    // O Segundo Ano usa outra regra, com notas de 0 a 100.
    $this->scenario->evaluationRule(['nota_maxima_geral' => 100, 'media' => 60] + $regra, $this->segundoAno);

    $this->matematica = $this->scenario->discipline('Matemática');
    $this->ciencias = $this->scenario->discipline('Ciências', grade: $this->segundoAno);
    // O mesmo componente nas duas séries: liga a Matemática do Segundo Ano à mesma disciplina.
    DB::table('modules.componente_curricular_ano_escolar')->insert(['componente_curricular_id' => $this->matematica->getKey(), 'ano_escolar_id' => $this->segundoAno->getKey(), 'carga_horaria' => 80, 'anos_letivos' => '{2026}']);
    DB::table('pmieducar.escola_serie_disciplina')->insert(['ref_ref_cod_escola' => $this->scenario->school->getKey(), 'ref_ref_cod_serie' => $this->segundoAno->getKey(), 'ref_cod_disciplina' => $this->matematica->getKey(), 'ativo' => 1, 'anos_letivos' => '{2026}']);

    $this->turma = turmaMultisseriada('Multi 1º e 2º ano', [$this->scenario->grade, $this->segundoAno]);
    $this->ana = $this->scenario->enrolled('Ana Alves', $this->turma, ['data_enturmacao' => '2026-02-01'])->registration;
    $this->bruno = $this->scenario->enrolled('Bruno Barros', $this->turma, ['data_enturmacao' => '2026-02-01'], ['ref_ref_cod_serie' => $this->segundoAno])->registration;
});

function turmaMultisseriada(string $nome, array $series, array $atributos = []): LegacySchoolClass
{
    $turma = test()->scenario->schoolClass($atributos + ['nm_turma' => $nome, 'multiseriada' => 1, 'ref_ref_cod_serie' => $series[0]]);

    foreach ($series as $serie) {
        LegacySchoolClassGrade::query()->create([
            'escola_id' => test()->scenario->school->getKey(),
            'serie_id' => $serie->getKey(),
            'turma_id' => $turma->getKey(),
            'boletim_id' => 1,
        ]);
    }

    return $turma;
}

test('as listas de turmas mostram todas as séries da turma multisseriada', function () {
    foreach (['/api/app/notas/turmas?ano=2026', '/api/app/chamada/turmas?ano=2026'] as $rota) {
        $this->actingAs($this->user)->getJson($rota)
            ->assertOk()
            ->assertJsonPath('turmas.0.nome', 'Multi 1º e 2º ano')
            ->assertJsonPath('turmas.0.serie', '1º ano e 2º ano')
            ->assertJsonPath('turmas.0.multisseriada', true);
    }
});

test('a chamada traz a série de cada aluno', function () {
    $this->actingAs($this->user)->getJson('/api/app/chamada?turma=' . $this->turma->getKey())
        ->assertOk()
        ->assertJsonPath('multisseriada', true)
        ->assertJsonPath('aviso', null)
        ->assertJsonPath('alunos.0.nome', 'Ana Alves')
        ->assertJsonPath('alunos.0.serie', '1º ano')
        ->assertJsonPath('alunos.1.nome', 'Bruno Barros')
        ->assertJsonPath('alunos.1.serie', '2º ano');

    $this->actingAs($this->user)->postJson('/api/app/chamada', ['turma' => $this->turma->getKey(), 'data' => '2026-03-10', 'faltaram' => [$this->bruno->ref_cod_aluno]])
        ->assertCreated()
        ->assertJsonPath('avisos', []);
});

test('nas notas, cada aluno vem com a sua série e a regra da série dele', function () {
    $this->actingAs($this->user)->getJson('/api/app/notas?turma=' . $this->turma->getKey() . '&componente=' . $this->matematica->getKey() . '&etapa=1')
        ->assertOk()
        ->assertJsonPath('multisseriada', true)
        ->assertJsonPath('notaMaxima', 10)
        ->assertJsonPath('aviso', null)
        ->assertJsonPath('alunos.0.serie', '1º ano')
        ->assertJsonPath('alunos.0.limites', null)
        ->assertJsonPath('alunos.1.serie', '2º ano')
        ->assertJsonPath('alunos.1.limites.notaMaxima', 100)
        ->assertJsonPath('alunos.1.limites.mediaAprovacao', 60);

    // A nota 80 vale para o Segundo Ano (0 a 100), mas não para o Primeiro (0 a 10).
    $nota = fn ($aluno, $valor) => $this->actingAs($this->user)->postJson('/api/app/notas', [
        'turma' => $this->turma->getKey(), 'componente' => $this->matematica->getKey(), 'etapa' => 1, 'aluno' => $aluno->ref_cod_aluno, 'nota' => $valor,
    ]);
    $nota($this->bruno, 80)->assertCreated();
    $nota($this->ana, 80)->assertUnprocessable();
});

test('componente de uma série só mostra os alunos dessa série e explica', function () {
    $this->actingAs($this->user)->getJson('/api/app/notas?turma=' . $this->turma->getKey() . '&componente=' . $this->ciencias->getKey() . '&etapa=1')
        ->assertOk()
        ->assertJsonCount(1, 'alunos')
        ->assertJsonPath('alunos.0.nome', 'Bruno Barros')
        ->assertJsonPath('aviso', 'Os alunos do 1º ano não aparecem aqui porque Ciências não faz parte da série deles.');
});

test('a BNCC sugere habilidades dos anos de todas as séries da turma', function () {
    $this->actingAs($this->user)->getJson('/api/app/diario/habilidades?turma=' . $this->turma->getKey() . '&componente=' . $this->matematica->getKey())
        ->assertOk()
        ->assertJsonPath('etapa', 'EF')
        ->assertJsonPath('anos', [1, 2]);

    // Ciências é só do Segundo Ano.
    $this->actingAs($this->user)->getJson('/api/app/diario/habilidades?turma=' . $this->turma->getKey() . '&componente=' . $this->ciencias->getKey())
        ->assertOk()
        ->assertJsonPath('anos', [2]);
});

test('as anotações mostram a série de cada aluno', function () {
    $this->actingAs($this->user)->getJson('/api/app/diario/anotacoes?turma=' . $this->turma->getKey())
        ->assertOk()
        ->assertJsonPath('turma.multisseriada', true)
        ->assertJsonPath('alunos.0.serie', '1º ano')
        ->assertJsonPath('alunos.1.serie', '2º ano');
});

describe('matrícula', function () {
    beforeEach(function () {
        LegacyGradeSequenceFactory::new()->create(['ref_serie_origem' => $this->scenario->grade, 'ref_serie_destino' => $this->segundoAno, 'ativo' => 1]);
        $this->turma->update(['max_aluno' => 20]);

        // Aprovado no 1º ano em 2025: vai para o 2º ano.
        $this->aluno = $this->scenario->student('Pedro Henrique');
        $this->scenario->registration(['ref_cod_aluno' => $this->aluno, 'ano' => 2025, 'aprovado' => RegistrationStatus::APPROVED]);
    });

    test('a turma multisseriada aparece para quem vai para uma das séries dela', function () {
        $this->actingAs($this->user)->getJson('/api/app/matriculas/turmas?ano=2026&aluno=' . $this->aluno->getKey())
            ->assertOk()
            ->assertJsonPath('serie', '2º ano')
            ->assertJsonCount(1, 'turmas')
            ->assertJsonPath('turmas.0.nome', 'Multi 1º e 2º ano')
            ->assertJsonPath('turmas.0.serieId', $this->segundoAno->getKey())
            ->assertJsonPath('turmas.0.serie', '2º ano')
            ->assertJsonPath('turmas.0.multisseriada', true)
            ->assertJsonPath('turmas.0.seriesDaTurma', '1º ano e 2º ano')
            ->assertJsonPath('turmas.0.vagas', 18);
    });

    test('aluno sem série definida vê uma opção para cada série da multisseriada', function () {
        $novo = $this->scenario->student('Aluno Novo');

        $this->actingAs($this->user)->getJson('/api/app/matriculas/turmas?ano=2026&aluno=' . $novo->getKey())
            ->assertOk()
            ->assertJsonCount(2, 'turmas')
            ->assertJsonPath('turmas.0.serie', '1º ano')
            ->assertJsonPath('turmas.1.serie', '2º ano');
    });

    test('matricula na série escolhida da turma multisseriada', function () {
        $pedido = ['aluno' => $this->aluno->getKey(), 'turma' => $this->turma->getKey(), 'data' => '10/02/2026'];

        $this->actingAs($this->user)->postJson('/api/app/matriculas', $pedido)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta turma é multisseriada. Escolha em qual série o aluno vai estudar.');

        $resposta = $this->actingAs($this->user)->postJson('/api/app/matriculas', $pedido + ['serie' => $this->segundoAno->getKey()])
            ->assertCreated()
            ->assertJsonPath('serie', '2º ano');

        $matricula = LegacyRegistration::query()->find($resposta->json('matricula'));

        expect($matricula->ref_ref_cod_serie)->toBe($this->segundoAno->getKey())
            ->and(LegacyEnrollment::query()->where('ref_cod_matricula', $matricula->getKey())->where('ref_cod_turma', $this->turma->getKey())->exists())->toBeTrue();
    });

    test('recusa série que não é da turma', function () {
        $terceiro = $this->scenario->grade('3º ano', ['descricao' => null, 'etapa_curso' => 3]);

        $this->actingAs($this->user)->postJson('/api/app/matriculas', ['aluno' => $this->aluno->getKey(), 'turma' => $this->turma->getKey(), 'data' => '10/02/2026', 'serie' => $terceiro->getKey()])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A série escolhida não faz parte desta turma.');
    });
});
