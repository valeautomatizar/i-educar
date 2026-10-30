<?php

use App\Models\DailyAttendance;
use App\Models\LegacySchoolClassTeacher;
use Database\Factories\EmployeeFactory;
use Database\Factories\LegacyMenuUserTypeFactory;
use Database\Factories\LegacyUserFactory;
use Database\Factories\MenuFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Request\Support\EnrollmentScenario;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    $this->travelTo('2026-03-20 10:00:00');
    $this->user = LegacyUserFactory::new()->admin()->create();
    $this->scenario = EnrollmentScenario::for($this->user, 2026);
});

/**
 * Turma com Ana e Bruno, numa regra que apura falta no geral ou por componente.
 */
function turmaDaChamada(int $tipoPresenca = RegraAvaliacao_Model_TipoPresenca::GERAL): void
{
    $t = test();
    $t->scenario->evaluationRule([
        'tipo_nota' => RegraAvaliacao_Model_Nota_TipoValor::NUMERICA,
        'tipo_presenca' => $tipoPresenca,
        'parecer_descritivo' => RegraAvaliacao_Model_TipoParecerDescritivo::NENHUM,
        'tipo_recuperacao_paralela' => RegraAvaliacao_Model_TipoRecuperacaoParalela::NAO_USAR,
        'media' => 6,
        'nota_maxima_geral' => 10,
        'nota_minima_geral' => 0,
    ]);
    $t->matematica = $t->scenario->discipline('Matemática');
    $t->portugues = $t->scenario->discipline('Língua Portuguesa');
    $t->turma = $t->scenario->schoolClass(['nm_turma' => '1º ano A']);
    $t->ana = $t->scenario->enrolled('Ana Alves', $t->turma, ['data_enturmacao' => '2026-02-01'])->registration;
    $t->bruno = $t->scenario->enrolled('Bruno Barros', $t->turma, ['data_enturmacao' => '2026-02-01'])->registration;
}

function fazerChamada(string $dia, array $faltaram, array $extra = [])
{
    return test()->actingAs(test()->user)->postJson('/api/app/chamada', $extra + [
        'turma' => test()->turma->getKey(),
        'data' => $dia,
        'faltaram' => $faltaram,
    ]);
}

function faltasGerais(int $matriculaId, int $etapa = 1): ?int
{
    $quantidade = DB::table('modules.falta_geral')
        ->join('modules.falta_aluno', 'falta_aluno.id', '=', 'falta_geral.falta_aluno_id')
        ->where('falta_aluno.matricula_id', $matriculaId)
        ->where('falta_geral.etapa', (string) $etapa)
        ->value('quantidade');

    return $quantidade === null ? null : (int) $quantidade;
}

test('sem login as rotas da chamada respondem 401', function () {
    $this->getJson('/api/app/chamada/turmas')->assertUnauthorized();
    $this->getJson('/api/app/chamada?turma=1')->assertUnauthorized();
    $this->postJson('/api/app/chamada')->assertUnauthorized();
});

test('salva a chamada do dia e a falta vai para o boletim', function () {
    turmaDaChamada();

    fazerChamada('2026-03-10', [$this->bruno->ref_cod_aluno])
        ->assertCreated()
        ->assertJsonPath('message', 'Chamada salva com sucesso.')
        ->assertJsonPath('presentes', 1)
        ->assertJsonPath('faltas', 1)
        ->assertJsonPath('avisos', []);

    expect(DailyAttendance::query()->count())->toBe(2)
        ->and(faltasGerais($this->bruno->getKey()))->toBe(1)
        ->and(faltasGerais($this->ana->getKey()))->toBe(0);

    $this->actingAs($this->user)->getJson('/api/app/chamada?turma=' . $this->turma->getKey() . '&data=2026-03-10')
        ->assertOk()
        ->assertJsonPath('turma.nome', '1º ano A')
        ->assertJsonPath('data', '2026-03-10')
        ->assertJsonPath('porComponente', false)
        ->assertJsonPath('componente', null)
        ->assertJsonPath('etapa.numero', 1)
        ->assertJsonPath('feita', true)
        ->assertJsonPath('podeLancar', true)
        ->assertJsonPath('alunos.0.nome', 'Ana Alves')
        ->assertJsonPath('alunos.0.numero', 1)
        ->assertJsonPath('alunos.0.presente', true)
        ->assertJsonPath('alunos.1.nome', 'Bruno Barros')
        ->assertJsonPath('alunos.1.presente', false)
        ->assertJsonPath('alunos.1.faltasNaEtapa', 1);
});

test('as faltas da etapa somam os dias e corrigir um dia não conta duas vezes', function () {
    turmaDaChamada();

    fazerChamada('2026-03-10', [$this->bruno->ref_cod_aluno])->assertCreated();
    fazerChamada('2026-03-11', [$this->bruno->ref_cod_aluno, $this->ana->ref_cod_aluno])->assertCreated();
    expect(faltasGerais($this->bruno->getKey()))->toBe(2)
        ->and(faltasGerais($this->ana->getKey()))->toBe(1);

    // Bruno chegou atrasado no dia 11: a professora corrige.
    fazerChamada('2026-03-11', [$this->ana->ref_cod_aluno])->assertCreated();
    expect(faltasGerais($this->bruno->getKey()))->toBe(1)
        ->and(DailyAttendance::query()->count())->toBe(4);
});

test('dia ainda sem chamada vem com todos sem marcação', function () {
    turmaDaChamada();

    $this->actingAs($this->user)->getJson('/api/app/chamada?turma=' . $this->turma->getKey())
        ->assertOk()
        ->assertJsonPath('data', '2026-03-20')
        ->assertJsonPath('feita', false)
        ->assertJsonPath('alunos.0.presente', null)
        ->assertJsonPath('alunos.0.faltasNaEtapa', 0);
});

test('com falta por componente, a chamada é de um componente e grava a falta nele', function () {
    turmaDaChamada(RegraAvaliacao_Model_TipoPresenca::POR_COMPONENTE);

    $this->actingAs($this->user)->getJson('/api/app/chamada?turma=' . $this->turma->getKey() . '&data=2026-03-10')
        ->assertOk()
        ->assertJsonPath('porComponente', true)
        ->assertJsonCount(2, 'componentes');

    fazerChamada('2026-03-10', [$this->ana->ref_cod_aluno], ['componente' => $this->matematica->getKey()])
        ->assertCreated()
        ->assertJsonPath('avisos', []);

    $falta = DB::table('modules.falta_componente_curricular')
        ->join('modules.falta_aluno', 'falta_aluno.id', '=', 'falta_componente_curricular.falta_aluno_id')
        ->where('falta_aluno.matricula_id', $this->ana->getKey())
        ->where('componente_curricular_id', $this->matematica->getKey())
        ->where('etapa', '1')
        ->value('quantidade');

    expect((int) $falta)->toBe(1)
        ->and(DailyAttendance::query()->where('discipline_id', $this->matematica->getKey())->count())->toBe(2);

    // Português do mesmo dia é outra chamada.
    $this->actingAs($this->user)->getJson('/api/app/chamada?turma=' . $this->turma->getKey() . '&data=2026-03-10&componente=' . $this->portugues->getKey())
        ->assertOk()
        ->assertJsonPath('feita', false);
});

test('recusa dia futuro e dia fora das etapas', function () {
    turmaDaChamada();

    fazerChamada('2026-03-21', [])->assertUnprocessable()
        ->assertJsonPath('message', 'Não dá para fazer a chamada de um dia que ainda não chegou.');

    // Férias de julho, entre os dois semestres.
    $this->travelTo('2026-07-20 10:00:00');
    fazerChamada('2026-07-10', [])->assertUnprocessable()
        ->assertJsonPath('message', 'Este dia não está em nenhuma etapa do calendário da turma.');

    expect(DailyAttendance::query()->count())->toBe(0);
});

test('recusa falta de aluno que não está na turma', function () {
    turmaDaChamada();
    $outro = $this->scenario->student('Carla Costa');

    fazerChamada('2026-03-10', [$outro->getKey()])->assertUnprocessable();

    expect(DailyAttendance::query()->count())->toBe(0);
});

test('aluno que entrou na turma depois do dia não aparece na chamada dele', function () {
    turmaDaChamada();
    $this->scenario->enrolled('Davi Dias', $this->turma, ['data_enturmacao' => '2026-03-15']);

    $this->actingAs($this->user)->getJson('/api/app/chamada?turma=' . $this->turma->getKey() . '&data=2026-03-10')
        ->assertOk()->assertJsonCount(2, 'alunos');

    $this->actingAs($this->user)->getJson('/api/app/chamada?turma=' . $this->turma->getKey() . '&data=2026-03-16')
        ->assertOk()->assertJsonCount(3, 'alunos');
});

test('a lista de turmas diz quais já têm a chamada de hoje', function () {
    turmaDaChamada();
    $outra = $this->scenario->schoolClass(['nm_turma' => '1º ano B']);

    fazerChamada('2026-03-20', [])->assertCreated();

    $this->actingAs($this->user)->getJson('/api/app/chamada/turmas?ano=2026')
        ->assertOk()
        ->assertJsonPath('turmas.0.nome', '1º ano A')
        ->assertJsonPath('turmas.0.chamadaDeHoje', true)
        ->assertJsonPath('turmas.1.nome', '1º ano B')
        ->assertJsonPath('turmas.1.chamadaDeHoje', false);
});

test('usuário sem permissão do diário recebe 403', function () {
    $semPermissao = LegacyUserFactory::new()->school()->withSchool($this->scenario->school)->create();

    $this->actingAs($semPermissao)->getJson('/api/app/chamada/turmas')->assertForbidden();
});

test('a ficha do aluno mostra a presença das chamadas e as faltas do boletim', function () {
    turmaDaChamada();

    fazerChamada('2026-03-10', [$this->bruno->ref_cod_aluno])->assertCreated();
    fazerChamada('2026-03-11', [])->assertCreated();

    $this->actingAs($this->user)->getJson('/api/app/alunos/' . $this->bruno->ref_cod_aluno)
        ->assertOk()
        ->assertJsonPath('presenca', 50)
        ->assertJsonPath('faltas', 1);

    $this->actingAs($this->user)->getJson('/api/app/alunos/' . $this->ana->ref_cod_aluno)
        ->assertOk()
        ->assertJsonPath('presenca', 100)
        ->assertJsonPath('faltas', 0);
});

test('o painel mostra a presença do mês e as turmas sem chamada de hoje', function () {
    turmaDaChamada();
    $professor = LegacyUserFactory::new()->school()->withSchool($this->scenario->school)->create();
    LegacyMenuUserTypeFactory::new()->create([
        'ref_cod_tipo_usuario' => $professor->ref_cod_tipo_usuario,
        'menu_id' => MenuFactory::new()->create(['process' => 642, 'parent_id' => null]),
    ]);
    EmployeeFactory::new()->create(['cod_servidor' => $professor->getKey(), 'ref_cod_instituicao' => $this->scenario->institution]);
    LegacySchoolClassTeacher::query()->create([
        'ano' => 2026,
        'instituicao_id' => $this->scenario->institution,
        'turma_id' => $this->turma->getKey(),
        'servidor_id' => $professor->getKey(),
        'funcao_exercida' => 1,
        'tipo_vinculo' => 1,
    ]);

    $this->actingAs($professor)->getJson('/api/app/painel')
        ->assertOk()
        ->assertJsonPath('numeros.presencaMes', null)
        ->assertJsonPath('pendencias.0.tipo', 'chamada')
        ->assertJsonPath('pendencias.0.detalhe', '1º ano A');

    // Sem chamada nenhuma no ano, a secretaria não é cobrada.
    $this->actingAs($this->user)->getJson('/api/app/painel')
        ->assertOk()->assertJsonCount(0, 'pendencias');

    $this->actingAs($professor)->postJson('/api/app/chamada', [
        'turma' => $this->turma->getKey(), 'data' => '2026-03-20', 'faltaram' => [$this->bruno->ref_cod_aluno],
    ])->assertCreated();

    $this->actingAs($professor)->getJson('/api/app/painel')
        ->assertOk()
        ->assertJsonPath('numeros.presencaMes', 50)
        ->assertJsonCount(0, 'pendencias');
});
