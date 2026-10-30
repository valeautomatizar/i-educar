<?php

use App\Models\ClassNote;
use App\Models\LegacySchoolClassTeacher;
use App\Models\LegacyUser;
use App\Models\LessonRecord;
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
    $this->matematica = $this->scenario->discipline('Matemática');
    $this->portugues = $this->scenario->discipline('Língua Portuguesa');
    $this->turma = $this->scenario->schoolClass(['nm_turma' => '5º ano B']);
    $this->ana = $this->scenario->enrolled('Ana Alves', $this->turma)->registration;
    $this->bruno = $this->scenario->enrolled('Bruno Barros', $this->turma)->registration;
    $this->ontem = now()->subDay()->format('Y-m-d');
    $this->anteontem = now()->subDays(2)->format('Y-m-d');
    $this->hoje = now()->format('Y-m-d');
});

function professorDoDiario(array $componentes): LegacyUser
{
    $professor = LegacyUserFactory::new()->school()->withSchool(test()->scenario->school)->create();
    LegacyMenuUserTypeFactory::new()->create([
        'ref_cod_tipo_usuario' => $professor->ref_cod_tipo_usuario,
        'menu_id' => MenuFactory::new()->create(['process' => 642, 'parent_id' => null]),
    ]);
    EmployeeFactory::new()->create(['cod_servidor' => $professor->getKey(), 'ref_cod_instituicao' => test()->scenario->institution]);
    $vinculo = LegacySchoolClassTeacher::query()->create([
        'ano' => 2030,
        'instituicao_id' => test()->scenario->institution,
        'turma_id' => test()->turma->getKey(),
        'servidor_id' => $professor->getKey(),
        'funcao_exercida' => 1,
        'tipo_vinculo' => 1,
    ]);
    $vinculo->disciplines()->attach($componentes);

    return $professor;
}

test('sem login as rotas do diário respondem 401', function () {
    $this->getJson('/api/app/diario/aula?turma=1')->assertUnauthorized();
    $this->putJson('/api/app/diario/aula')->assertUnauthorized();
    $this->getJson('/api/app/diario/anotacoes?turma=1')->assertUnauthorized();
    $this->postJson('/api/app/diario/anotacoes')->assertUnauthorized();
    $this->deleteJson('/api/app/diario/anotacoes/1')->assertUnauthorized();
});

test('registra o que foi ensinado e mostra na aula do dia', function () {
    $this->actingAs($this->user)->putJson('/api/app/diario/aula', [
        'turma' => $this->turma->getKey(),
        'componente' => $this->matematica->getKey(),
        'data' => $this->ontem,
        'conteudo' => '  Frações: metade e um quarto.  ',
    ])->assertCreated()->assertJsonPath('aula.conteudo', 'Frações: metade e um quarto.');

    $this->actingAs($this->user)
        ->getJson('/api/app/diario/aula?turma=' . $this->turma->getKey() . '&componente=' . $this->matematica->getKey() . '&data=' . $this->ontem)
        ->assertOk()
        ->assertJsonPath('turma.nome', '5º ano B')
        ->assertJsonPath('componente', $this->matematica->getKey())
        ->assertJsonPath('aula.conteudo', 'Frações: metade e um quarto.')
        ->assertJsonPath('ultima', null);
});

test('salvar de novo no mesmo dia atualiza o registro em vez de duplicar', function () {
    $dados = ['turma' => $this->turma->getKey(), 'componente' => $this->matematica->getKey(), 'data' => $this->ontem];

    $this->actingAs($this->user)->putJson('/api/app/diario/aula', $dados + ['conteudo' => 'Primeira versão'])->assertCreated();
    $this->actingAs($this->user)->putJson('/api/app/diario/aula', $dados + ['conteudo' => 'Versão corrigida'])->assertOk();

    expect(LessonRecord::query()->count())->toBe(1)
        ->and(LessonRecord::query()->value('content'))->toBe('Versão corrigida');
});

test('mostra a última aula anterior para repetir', function () {
    $dados = ['turma' => $this->turma->getKey(), 'componente' => $this->matematica->getKey()];
    $this->actingAs($this->user)->putJson('/api/app/diario/aula', $dados + ['data' => $this->anteontem, 'conteudo' => 'Aula de ontem'])->assertCreated();

    $this->actingAs($this->user)
        ->getJson('/api/app/diario/aula?turma=' . $this->turma->getKey() . '&componente=' . $this->matematica->getKey() . '&data=' . $this->ontem)
        ->assertOk()
        ->assertJsonPath('aula', null)
        ->assertJsonPath('ultima.data', $this->anteontem)
        ->assertJsonPath('ultima.conteudo', 'Aula de ontem');
});

test('não aceita aula no futuro nem conteúdo vazio', function () {
    $dados = ['turma' => $this->turma->getKey(), 'componente' => $this->matematica->getKey()];

    $this->actingAs($this->user)->putJson('/api/app/diario/aula', $dados + ['data' => now()->addDay()->format('Y-m-d'), 'conteudo' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('data');
    $this->actingAs($this->user)->putJson('/api/app/diario/aula', $dados + ['data' => $this->ontem, 'conteudo' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors('conteudo');
});

test('professor só registra aula nas turmas e componentes em que leciona', function () {
    $professor = professorDoDiario([$this->portugues->getKey()]);
    $outraTurma = $this->scenario->schoolClass(['nm_turma' => '5º ano C']);

    $this->actingAs($professor)->getJson('/api/app/diario/aula?turma=' . $this->turma->getKey())
        ->assertOk()->assertJsonCount(1, 'componentes')->assertJsonPath('componente', $this->portugues->getKey());

    $this->actingAs($professor)->putJson('/api/app/diario/aula', [
        'turma' => $this->turma->getKey(), 'componente' => $this->matematica->getKey(), 'data' => $this->ontem, 'conteudo' => 'x',
    ])->assertForbidden();

    $this->actingAs($professor)->getJson('/api/app/diario/aula?turma=' . $outraTurma->getKey())->assertForbidden();
});

test('usuário sem permissão do diário recebe 403', function () {
    $semPermissao = LegacyUserFactory::new()->school()->withSchool($this->scenario->school)->create();

    $this->actingAs($semPermissao)->getJson('/api/app/diario/aula?turma=' . $this->turma->getKey())->assertForbidden();
});

test('anota sobre a turma e sobre um aluno e lista as anotações', function () {
    $this->actingAs($this->user)->postJson('/api/app/diario/anotacoes', [
        'turma' => $this->turma->getKey(), 'texto' => 'Turma agitada depois do recreio.', 'data' => $this->ontem,
    ])->assertCreated()->assertJsonPath('anotacao.aluno', null);

    $this->actingAs($this->user)->postJson('/api/app/diario/anotacoes', [
        'turma' => $this->turma->getKey(), 'aluno' => $this->ana->ref_cod_aluno, 'texto' => 'Ana ajudou os colegas.', 'data' => $this->hoje,
    ])->assertCreated()->assertJsonPath('anotacao.aluno.nome', 'Ana Alves');

    $this->actingAs($this->user)->getJson('/api/app/diario/anotacoes?turma=' . $this->turma->getKey())
        ->assertOk()
        ->assertJsonCount(2, 'anotacoes')
        ->assertJsonPath('anotacoes.0.texto', 'Ana ajudou os colegas.')
        ->assertJsonPath('anotacoes.0.podeApagar', true)
        ->assertJsonPath('alunos.0.nome', 'Ana Alves')
        ->assertJsonPath('alunos.1.nome', 'Bruno Barros');

    $this->actingAs($this->user)->getJson('/api/app/diario/anotacoes?turma=' . $this->turma->getKey() . '&aluno=' . $this->ana->ref_cod_aluno)
        ->assertOk()->assertJsonCount(1, 'anotacoes');
});

test('recusa anotação sobre aluno de outra turma', function () {
    $outraTurma = $this->scenario->schoolClass(['nm_turma' => '5º ano C']);
    $carla = $this->scenario->enrolled('Carla Costa', $outraTurma)->registration;

    $this->actingAs($this->user)->postJson('/api/app/diario/anotacoes', [
        'turma' => $this->turma->getKey(), 'aluno' => $carla->ref_cod_aluno, 'texto' => 'x',
    ])->assertUnprocessable();

    expect(ClassNote::query()->count())->toBe(0);
});

test('só quem escreveu apaga a anotação', function () {
    $professor = professorDoDiario([$this->portugues->getKey()]);
    $anotacao = ClassNote::query()->create([
        'school_class_id' => $this->turma->getKey(), 'user_id' => $this->user->getKey(), 'date' => $this->ontem, 'text' => 'Do administrador',
    ]);

    $this->actingAs($professor)->deleteJson('/api/app/diario/anotacoes/' . $anotacao->getKey())->assertForbidden();
    $this->actingAs($this->user)->deleteJson('/api/app/diario/anotacoes/' . $anotacao->getKey())->assertOk()->assertJsonPath('apagada', true);

    expect(ClassNote::query()->count())->toBe(0)
        ->and(ClassNote::withTrashed()->count())->toBe(1);
});
