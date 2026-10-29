<?php

use App\Models\LegacyDisciplineScore;
use App\Models\LegacySchoolClassTeacher;
use App\Models\ReleasePeriod;
use Database\Factories\EmployeeFactory;
use Database\Factories\LegacyMenuUserTypeFactory;
use Database\Factories\LegacyUserFactory;
use Database\Factories\MenuFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Request\Support\EnrollmentScenario;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    $this->user = LegacyUserFactory::new()->admin()->create();
    $this->scenario = EnrollmentScenario::for($this->user, 2030);
    $this->scenario->evaluationRule([
        'tipo_nota' => RegraAvaliacao_Model_Nota_TipoValor::NUMERICA,
        'tipo_presenca' => RegraAvaliacao_Model_TipoPresenca::POR_COMPONENTE,
        'parecer_descritivo' => RegraAvaliacao_Model_TipoParecerDescritivo::NENHUM,
        'tipo_recuperacao_paralela' => RegraAvaliacao_Model_TipoRecuperacaoParalela::NAO_USAR,
        'media' => 6,
        'nota_maxima_geral' => 10,
        'nota_minima_geral' => 0,
    ]);
    $this->matematica = $this->scenario->discipline('Matemática');
    $this->portugues = $this->scenario->discipline('Língua Portuguesa');
    $this->turma = $this->scenario->schoolClass(['nm_turma' => '1º ano A']);
    $this->ana = $this->scenario->enrolled('Ana Alves', $this->turma)->registration;
    $this->bruno = $this->scenario->enrolled('Bruno Barros', $this->turma)->registration;
});

function lancar(array $dados = [])
{
    return test()->actingAs(test()->user)->postJson('/api/app/notas', $dados + [
        'turma' => test()->turma->getKey(),
        'componente' => test()->matematica->getKey(),
        'etapa' => 1,
        'aluno' => test()->ana->ref_cod_aluno,
        'nota' => 7.5,
    ]);
}

test('sem login as rotas de notas respondem 401', function () {
    $this->getJson('/api/app/notas/turmas')->assertUnauthorized();
    $this->getJson('/api/app/notas?turma=1')->assertUnauthorized();
    $this->postJson('/api/app/notas')->assertUnauthorized();
});

test('lista as turmas do ano', function () {
    $this->actingAs($this->user)->getJson('/api/app/notas/turmas?ano=2030')
        ->assertOk()
        ->assertJsonPath('turmas.0.nome', '1º ano A')
        ->assertJsonPath('turmas.0.escola', 'Escola Aurora');
});

test('lança a nota e ela aparece no diário da turma com a média', function () {
    lancar()->assertCreated()->assertJsonPath('message', 'Nota salva com sucesso.');

    expect((float) LegacyDisciplineScore::query()->where('componente_curricular_id', $this->matematica->getKey())->value('nota_original'))->toBe(7.5);

    $this->actingAs($this->user)->getJson('/api/app/notas?turma=' . $this->turma->getKey() . '&componente=' . $this->matematica->getKey() . '&etapa=1')
        ->assertOk()
        ->assertJsonPath('turma.nome', '1º ano A')
        ->assertJsonPath('componentes.0.nome', 'Língua Portuguesa')
        ->assertJsonPath('componentes.1.nome', 'Matemática')
        ->assertJsonPath('etapas.0.nome', '1º Semestre')
        ->assertJsonPath('etapa', 1)
        ->assertJsonPath('tipoNota', 'numerica')
        ->assertJsonPath('mediaAprovacao', 6)
        ->assertJsonPath('notaMaxima', 10)
        ->assertJsonPath('podeLancar', true)
        ->assertJsonPath('alunos.0.nome', 'Ana Alves')
        ->assertJsonPath('alunos.0.nota', 7.5)
        ->assertJsonPath('alunos.1.nome', 'Bruno Barros')
        ->assertJsonPath('alunos.1.nota', null);
});

test('recusa nota acima da máxima da regra', function () {
    lancar(['nota' => 11])->assertUnprocessable();

    expect(LegacyDisciplineScore::query()->count())->toBe(0);
});

test('usuário sem permissão de lançar notas recebe 403', function () {
    $semPermissao = LegacyUserFactory::new()->school()->withSchool($this->scenario->school)->create();

    $this->actingAs($semPermissao)->getJson('/api/app/notas/turmas')->assertForbidden();
});

test('professor só vê e lança nas turmas e componentes em que leciona', function () {
    $professor = LegacyUserFactory::new()->school()->withSchool($this->scenario->school)->create();
    LegacyMenuUserTypeFactory::new()->create([
        'ref_cod_tipo_usuario' => $professor->ref_cod_tipo_usuario,
        'menu_id' => MenuFactory::new()->create(['process' => 642, 'parent_id' => null]),
    ]);
    EmployeeFactory::new()->create(['cod_servidor' => $professor->getKey(), 'ref_cod_instituicao' => $this->scenario->institution]);
    $vinculo = LegacySchoolClassTeacher::query()->create([
        'ano' => 2030,
        'instituicao_id' => $this->scenario->institution,
        'turma_id' => $this->turma->getKey(),
        'servidor_id' => $professor->getKey(),
        'funcao_exercida' => 1,
        'tipo_vinculo' => 1,
    ]);
    $vinculo->disciplines()->attach($this->portugues->getKey());
    $outraTurma = $this->scenario->schoolClass(['nm_turma' => '1º ano B']);

    $this->actingAs($professor)->getJson('/api/app/notas/turmas?ano=2030')
        ->assertOk()->assertJsonCount(1, 'turmas')->assertJsonPath('turmas.0.nome', '1º ano A');

    $this->actingAs($professor)->getJson('/api/app/notas?turma=' . $this->turma->getKey())
        ->assertOk()->assertJsonCount(1, 'componentes')->assertJsonPath('componente', $this->portugues->getKey());

    $this->actingAs($professor)->getJson('/api/app/notas?turma=' . $outraTurma->getKey())->assertForbidden();

    $this->actingAs($professor)->postJson('/api/app/notas', [
        'turma' => $this->turma->getKey(), 'componente' => $this->matematica->getKey(), 'etapa' => 1, 'aluno' => $this->ana->ref_cod_aluno, 'nota' => 8,
    ])->assertForbidden();
});

test('fora do período de lançamento o diário avisa e a nota é recusada', function () {
    $tipoEtapa = $this->turma->stages()->first()->ref_cod_modulo;
    $periodo = ReleasePeriod::query()->create(['year' => 2030, 'stage_type_id' => $tipoEtapa, 'stage' => 1]);
    $periodo->schools()->attach($this->scenario->school->getKey());
    $periodo->periodDates()->create(['start_date' => '2000-01-01', 'end_date' => '2000-01-31']);

    $this->actingAs($this->user)->getJson('/api/app/notas?turma=' . $this->turma->getKey() . '&etapa=1')
        ->assertOk()
        ->assertJsonPath('podeLancar', true);

    $comum = LegacyUserFactory::new()->institutional()->create(['ref_cod_instituicao' => $this->scenario->institution]);
    LegacyMenuUserTypeFactory::new()->create([
        'ref_cod_tipo_usuario' => $comum->ref_cod_tipo_usuario,
        'menu_id' => MenuFactory::new()->create(['process' => 642, 'parent_id' => null]),
    ]);

    $this->actingAs($comum)->getJson('/api/app/notas?turma=' . $this->turma->getKey() . '&etapa=1')
        ->assertOk()
        ->assertJsonPath('podeLancar', false)
        ->assertJsonPath('motivo', 'Não é permitido realizar esta alteração fora do período de lançamento de notas/faltas.');

    $this->actingAs($comum)->postJson('/api/app/notas', [
        'turma' => $this->turma->getKey(), 'componente' => $this->matematica->getKey(), 'etapa' => 1, 'aluno' => $this->ana->ref_cod_aluno, 'nota' => 8,
    ])->assertUnprocessable();

    expect(LegacyDisciplineScore::query()->count())->toBe(0);
});
