<?php

use App\Models\LegacyDisciplineScore;
use App\Models\LegacyDisciplineScoreAverage;
use App\Services\ModelosDeRegraDeAvaliacao;
use Database\Factories\LegacyUserFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Request\Support\EnrollmentScenario;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    $this->user = LegacyUserFactory::new()->admin()->create();
    $this->scenario = EnrollmentScenario::for($this->user, 2030);
    $this->regras = app(ModelosDeRegraDeAvaliacao::class)->aplicar('pernambuco', $this->scenario->institution);
    app(ModelosDeRegraDeAvaliacao::class)->vincular($this->regras['nota'], [$this->scenario->grade->getKey()], 2030);
    $this->matematica = $this->scenario->discipline('Matemática');
    $this->turma = $this->scenario->schoolClass(['nm_turma' => '3º ano A']);
    $this->ana = $this->scenario->enrolled('Ana Alves', $this->turma)->registration;
});

function lancarPE(array $dados = [])
{
    return test()->actingAs(test()->user)->postJson('/api/app/notas', $dados + [
        'turma' => test()->turma->getKey(),
        'componente' => test()->matematica->getKey(),
        'etapa' => 1,
        'aluno' => test()->ana->ref_cod_aluno,
    ]);
}

function diarioPE(string $etapa = '1')
{
    return test()->actingAs(test()->user)->getJson('/api/app/notas?turma=' . test()->turma->getKey() . '&componente=' . test()->matematica->getKey() . '&etapa=' . $etapa);
}

function notaDaEtapa(string $etapa): LegacyDisciplineScore
{
    return LegacyDisciplineScore::query()->where('componente_curricular_id', test()->matematica->getKey())->where('etapa', $etapa)->firstOrFail();
}

test('o comando carrega o modelo de Pernambuco e rodar de novo não duplica', function () {
    $this->artisan('regra-avaliacao:modelo', ['estado' => 'pernambuco', '--instituicao' => $this->scenario->institution])->assertSuccessful();
    $this->artisan('regra-avaliacao:modelo', ['estado' => 'Pernambuco', '--instituicao' => $this->scenario->institution])->assertSuccessful();

    $regras = DB::table('modules.regra_avaliacao')->where('instituicao_id', $this->scenario->institution)->where('nome', 'like', 'PE - %')->get()->keyBy('nome');
    expect($regras)->toHaveCount(3);

    $nota = $regras['PE - nota (3º ano em diante)'];
    expect((float) $nota->media)->toBe(6.0)
        ->and((float) $nota->media_recuperacao)->toBe(6.0)
        ->and((float) $nota->media_recuperacao_paralela)->toBe(6.0)
        ->and((int) $nota->qtd_disciplinas_dependencia)->toBe(3)
        ->and(DB::table('modules.formula_media')->where('id', $nota->formula_recuperacao_id)->value('formula_media'))->toBe('( Se / Et ) > Rc ? ( Se / Et ) : Rc')
        ->and(DB::table('modules.tabela_arredondamento_valor')->where('tabela_arredondamento_id', $nota->tabela_arredondamento_id)->count())->toBe(8)
        ->and($regras['PE - parecer (1º e 2º ano)']->parecer_descritivo)->toBe(RegraAvaliacao_Model_TipoParecerDescritivo::ETAPA_GERAL);

    $this->artisan('regra-avaliacao:modelo', ['estado' => 'atlantida'])->assertFailed();
});

test('a recuperação paralela fica gravada e vale a maior nota', function () {
    lancarPE(['nota' => 4.5, 'recuperacao' => 7])->assertCreated();

    $nota = notaDaEtapa('1');
    expect((float) $nota->nota_original)->toBe(4.5)
        ->and((float) $nota->nota_recuperacao)->toBe(7.0)
        ->and((float) $nota->nota)->toBe(7.0);

    diarioPE()->assertOk()
        ->assertJsonPath('recuperacaoParalela.media', 6)
        ->assertJsonPath('alunos.0.nota', 4.5)
        ->assertJsonPath('alunos.0.recuperacao', 7)
        ->assertJsonPath('alunos.0.notaFinal', 7);

    // Corrigir só a nota não apaga a recuperação já lançada.
    lancarPE(['nota' => 5])->assertCreated();
    expect((float) notaDaEtapa('1')->nota_recuperacao)->toBe(7.0);

    // Mandar a recuperação vazia apaga.
    lancarPE(['nota' => 5, 'recuperacao' => null])->assertCreated();
    expect(notaDaEtapa('1')->nota_recuperacao)->toBeNull();
});

test('a nota sobe para o meio ponto seguinte', function () {
    lancarPE(['nota' => 6.3])->assertCreated();
    expect((float) notaDaEtapa('1')->nota_arredondada)->toBe(6.5);

    lancarPE(['nota' => 6.7])->assertCreated();
    expect((float) notaDaEtapa('1')->nota_arredondada)->toBe(7.0);
});

test('a recuperação final mostra a nota necessária e vale a maior entre ela e a média', function () {
    lancarPE(['nota' => 4, 'etapa' => 1])->assertCreated();
    lancarPE(['nota' => 5, 'etapa' => 2])->assertCreated();

    diarioPE('1')->assertOk()->assertJsonPath('etapas.2.numero', 'Rc')->assertJsonPath('etapas.2.nome', 'Recuperação final');

    diarioPE('Rc')->assertOk()
        ->assertJsonPath('etapa', 'Rc')
        ->assertJsonPath('recuperacaoParalela', null)
        ->assertJsonPath('mediaAprovacao', 6)
        ->assertJsonPath('alunos.0.notaNecessaria', 6);

    lancarPE(['nota' => 6.5, 'etapa' => 'Rc'])->assertCreated();

    expect((float) LegacyDisciplineScoreAverage::query()->where('componente_curricular_id', $this->matematica->getKey())->value('media'))->toBe(6.5);
    diarioPE('Rc')->assertOk()->assertJsonPath('alunos.0.nota', 6.5);
});

test('turma do 1º e 2º ano lança parecer por trimestre', function () {
    $segundoAno = $this->scenario->grade('Segundo Ano');
    app(ModelosDeRegraDeAvaliacao::class)->vincular($this->regras['parecer'], [$segundoAno->getKey()], 2030);
    $this->scenario->discipline('Língua Portuguesa', 80, $segundoAno);
    $turma = $this->scenario->schoolClass(['nm_turma' => '2º ano A', 'ref_ref_cod_serie' => $segundoAno]);
    $bia = $this->scenario->enrolled('Bia Borges', $turma, [], ['ref_ref_cod_serie' => $segundoAno])->registration;

    $this->actingAs($this->user)->postJson('/api/app/notas/parecer', [
        'turma' => $turma->getKey(), 'etapa' => 1, 'aluno' => $bia->ref_cod_aluno, 'parecer' => 'Lê palavras simples com ajuda.',
    ])->assertCreated();

    $this->actingAs($this->user)->getJson('/api/app/notas?turma=' . $turma->getKey() . '&etapa=1')
        ->assertOk()
        ->assertJsonPath('tipoNota', 'nenhuma')
        ->assertJsonPath('parecer', 'etapa_geral')
        ->assertJsonPath('etapas.1.numero', 2)
        ->assertJsonCount(2, 'etapas')
        ->assertJsonPath('alunos.0.parecer', 'Lê palavras simples com ajuda.');

    // Na turma com nota, a regra não usa parecer.
    $this->actingAs($this->user)->postJson('/api/app/notas/parecer', [
        'turma' => $this->turma->getKey(), 'etapa' => 1, 'aluno' => $this->ana->ref_cod_aluno, 'parecer' => 'Texto',
    ])->assertUnprocessable()->assertJsonPath('message', 'A regra de avaliação desta turma não usa parecer.');
});
