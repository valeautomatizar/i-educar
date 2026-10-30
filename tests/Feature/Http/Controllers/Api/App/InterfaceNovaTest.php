<?php

use App\Models\LegacyTransferRequest;
use App\Models\RegistrationStatus;
use Database\Factories\LegacyDisciplineFactory;
use Database\Factories\LegacyMenuUserTypeFactory;
use Database\Factories\LegacySchoolFactory;
use Database\Factories\LegacyTransferTypeFactory;
use Database\Factories\LegacyUserFactory;
use Database\Factories\MenuFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Request\Support\EnrollmentScenario;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    $this->user = LegacyUserFactory::new()->admin()->create();
    $this->scenario = EnrollmentScenario::for($this->user, 2030);
});

test('sem login as rotas respondem 401', function (string $url) {
    $this->getJson($url)->assertUnauthorized();
})->with(['/api/app/sessao', '/api/app/painel', '/api/app/alunos?busca=ana', '/api/app/alunos/1']);

test('a sessão diz quem está logado e se precisa trocar a senha', function () {
    $this->user->employee->update(['force_reset_password' => true]);

    $this->actingAs($this->user)->getJson('/api/app/sessao')
        ->assertOk()
        ->assertJsonPath('usuario.id', $this->user->getKey())
        ->assertJsonPath('usuario.nome', $this->user->name)
        ->assertJsonPath('trocarSenha', true)
        ->assertJsonPath('linkTrocarSenha', '/alterar-senha')
        ->assertJsonPath('permissoes', ['alunos' => true, 'matricula' => true, 'diario' => true]);
});

test('a sessão só libera o que o tipo de usuário pode usar, e a ficha segue a mesma regra', function () {
    $professora = LegacyUserFactory::new()->school()->withSchool($this->scenario->school)->create();
    LegacyMenuUserTypeFactory::new()->create([
        'ref_cod_tipo_usuario' => $professora->ref_cod_tipo_usuario,
        'menu_id' => MenuFactory::new()->create(['process' => 642, 'parent_id' => null]),
    ]);

    $this->actingAs($professora)->getJson('/api/app/sessao')
        ->assertOk()
        ->assertJsonPath('permissoes', ['alunos' => false, 'matricula' => false, 'diario' => true]);

    $this->actingAs($professora)->getJson('/api/app/alunos?busca=ana')->assertForbidden();
});

test('o painel conta alunos cursando, turmas, vagas e transferências pendentes do ano', function () {
    $turma = $this->scenario->schoolClass(['max_aluno' => 30]);
    $this->scenario->enrolled('Ana Alves', $turma);
    $bruno = $this->scenario->enrolled('Bruno Barros', $turma);
    LegacyTransferRequest::query()->create([
        'ref_cod_matricula_saida' => $bruno->ref_cod_matricula,
        'ref_usuario_cad' => $this->user->getKey(),
        'observacao' => 'Mudança de cidade',
        'ativo' => 1,
        'data_cadastro' => now(),
        'ref_cod_transferencia_tipo' => LegacyTransferTypeFactory::new()->create()->getKey(),
    ]);

    $this->actingAs($this->user)->getJson('/api/app/painel?ano=2030')
        ->assertOk()
        ->assertJsonPath('anoLetivo', 2030)
        ->assertJsonPath('numeros.alunosAtivos', 2)
        ->assertJsonPath('numeros.turmas', 1)
        ->assertJsonPath('numeros.vagasLivres', 28)
        ->assertJsonPath('numeros.presencaMes', null)
        ->assertJsonPath('pendencias.0.tipo', 'transferencia')
        ->assertJsonPath('pendencias.0.titulo', '1 pedido de transferência');
});

test('busca aluno pelo nome, sem diferenciar acento, e ignora busca curta', function () {
    $turma = $this->scenario->schoolClass();
    $this->scenario->enrolled('João Évora', $turma);
    $this->scenario->enrolled('Maria Souza', $turma);

    $this->actingAs($this->user);

    $this->getJson('/api/app/alunos?busca=j')->assertOk()->assertExactJson(['alunos' => []]);

    $resposta = $this->getJson('/api/app/alunos?busca=evora')->assertOk();

    expect($resposta->json('alunos'))->toHaveCount(1)
        ->and($resposta->json('alunos.0.nome'))->toBe('João Évora')
        ->and($resposta->json('alunos.0.detalhe'))->toContain('Cursando');
});

test('a ficha traz o aluno, a turma atual e o histórico de matrículas', function () {
    $turma = $this->scenario->schoolClass(['nm_turma' => '5º ano A']);
    $enturmacao = $this->scenario->enrolled('Ana Alves', $turma);
    $aluno = $enturmacao->registration->ref_cod_aluno;
    $this->scenario->registration(['ref_cod_aluno' => $aluno, 'ano' => 2029, 'aprovado' => RegistrationStatus::APPROVED]);

    $this->actingAs($this->user)->getJson('/api/app/alunos/' . $aluno)
        ->assertOk()
        ->assertJsonPath('nome', 'Ana Alves')
        ->assertJsonPath('turma', '5º ano A')
        ->assertJsonPath('situacao', 'cursando')
        ->assertJsonPath('situacaoTexto', 'Cursando')
        ->assertJsonPath('historico.0.ano', 2030)
        ->assertJsonPath('historico.1.ano', 2029)
        ->assertJsonPath('historico.1.situacao', 'aprovado');
});

test('usuário de escola não abre a ficha de aluno de outra escola', function () {
    $turma = $this->scenario->schoolClass();
    $aluno = $this->scenario->enrolled('Ana Alves', $turma)->registration->ref_cod_aluno;

    $deOutraEscola = LegacyUserFactory::new()->school()->withSchool(LegacySchoolFactory::new()->create())->create();
    LegacyMenuUserTypeFactory::new()->create([
        'ref_cod_tipo_usuario' => $deOutraEscola->ref_cod_tipo_usuario,
        'menu_id' => MenuFactory::new()->create(['process' => 680, 'parent_id' => null]),
    ]);

    $this->actingAs($deOutraEscola)->getJson('/api/app/alunos/' . $aluno)->assertForbidden();
    $this->actingAs($deOutraEscola)->getJson('/api/app/alunos?busca=ana')->assertOk()->assertExactJson(['alunos' => []]);
});

test('a ficha traz a média geral das notas da matrícula atual', function () {
    $turma = $this->scenario->schoolClass();
    $matricula = $this->scenario->enrolled('Ana Alves', $turma)->registration;
    $notaAluno = DB::table('modules.nota_aluno')->insertGetId(['matricula_id' => $matricula->getKey()]);

    foreach (['7.0', '8.5', 'A'] as $i => $media) {
        DB::table('modules.nota_componente_curricular_media')->insert([
            'nota_aluno_id' => $notaAluno,
            'componente_curricular_id' => LegacyDisciplineFactory::new()->create()->getKey(),
            'media' => 0,
            'media_arredondada' => $media,
            'etapa' => '1',
        ]);
    }

    $this->actingAs($this->user)->getJson('/api/app/alunos/' . $matricula->ref_cod_aluno)
        ->assertOk()
        ->assertJsonPath('media', 7.8);
});
