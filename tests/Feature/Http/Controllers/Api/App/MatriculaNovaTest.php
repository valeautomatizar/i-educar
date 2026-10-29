<?php

use App\Jobs\UpdateBlockedSituationRegistration;
use App\Models\LegacyEnrollment;
use App\Models\LegacyInstitution;
use App\Models\LegacyRegistration;
use App\Models\RegistrationStatus;
use Database\Factories\LegacyGradeSequenceFactory;
use Database\Factories\LegacyIndividualFactory;
use Database\Factories\LegacyMenuUserTypeFactory;
use Database\Factories\LegacyPersonFactory;
use Database\Factories\LegacySchoolFactory;
use Database\Factories\LegacyStudentFactory;
use Database\Factories\LegacyUserFactory;
use Database\Factories\MenuFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\Request\Support\EnrollmentScenario;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    $this->user = LegacyUserFactory::new()->admin()->create();
    $this->scenario = EnrollmentScenario::for($this->user, 2030);
    $this->segundoAno = $this->scenario->grade('Segundo Ano');
    LegacyGradeSequenceFactory::new()->create([
        'ref_serie_origem' => $this->scenario->grade,
        'ref_serie_destino' => $this->segundoAno,
        'ativo' => 1,
    ]);

    // Aluno aprovado no Primeiro Ano em 2029: a série seguinte é o Segundo Ano.
    $this->aluno = $this->scenario->student('Pedro Henrique Alves');
    $this->scenario->registration(['ref_cod_aluno' => $this->aluno, 'ano' => 2029, 'aprovado' => RegistrationStatus::APPROVED]);

    $this->turma = $this->scenario->schoolClass(['nm_turma' => '2º ano A', 'ref_ref_cod_serie' => $this->segundoAno, 'max_aluno' => 25]);
});

function matricular(array $dados = [])
{
    return test()->actingAs(test()->user)->postJson('/api/app/matriculas', $dados + [
        'aluno' => test()->aluno->getKey(),
        'turma' => test()->turma->getKey(),
        'data' => '10/02/2030',
    ]);
}

test('sem login as rotas de matrícula respondem 401', function () {
    $this->getJson('/api/app/matriculas/turmas?aluno=1')->assertUnauthorized();
    $this->postJson('/api/app/matriculas')->assertUnauthorized();
});

test('lista só as turmas com vaga da série seguinte do aluno', function () {
    $cheia = $this->scenario->schoolClass(['nm_turma' => '2º ano B', 'ref_ref_cod_serie' => $this->segundoAno, 'max_aluno' => 1]);
    $this->scenario->enroll($this->scenario->registration(['ref_cod_aluno' => $this->scenario->student('Outro'), 'ref_ref_cod_serie' => $this->segundoAno]), $cheia);
    $this->scenario->schoolClass(['nm_turma' => '1º ano A']);

    $this->actingAs($this->user)->getJson('/api/app/matriculas/turmas?ano=2030&aluno=' . $this->aluno->getKey())
        ->assertOk()
        ->assertJsonPath('serie', 'Segundo Ano')
        ->assertJsonCount(1, 'turmas')
        ->assertJsonPath('turmas.0.nome', '2º ano A')
        ->assertJsonPath('turmas.0.vagas', 25)
        ->assertJsonPath('turmas.0.escola', 'Escola Aurora');
});

test('matricula e enturma o aluno', function () {
    $resposta = matricular()->assertCreated()->assertJsonPath('turma', '2º ano A');

    $matricula = LegacyRegistration::query()->find($resposta->json('matricula'));

    expect($matricula)
        ->ref_cod_aluno->toBe($this->aluno->getKey())
        ->ref_ref_cod_serie->toBe($this->segundoAno->getKey())
        ->ano->toBe(2030)
        ->aprovado->toBe(RegistrationStatus::ONGOING)
        ->and($matricula->data_matricula->format('Y-m-d'))->toBe('2030-02-10')
        ->and(LegacyEnrollment::query()->where('ref_cod_matricula', $matricula->getKey())->where('ref_cod_turma', $this->turma->getKey())->where('ativo', 1)->exists())->toBeTrue();
});

test('recusa matricular de novo na mesma série', function () {
    matricular()->assertCreated();

    matricular()->assertUnprocessable()
        ->assertJsonPath('message', 'Este aluno já está matriculado nesta série e curso, não é possivel matricular um aluno mais de uma vez na mesma série.');
});

test('recusa data fora do ano letivo, depois do fechamento e em formato errado', function () {
    LegacyInstitution::query()->whereKey($this->scenario->institution)->update(['data_fechamento' => null]);

    matricular(['data' => '20/12/2030'])->assertUnprocessable()
        ->assertJsonPath('message', 'A data de matrícula precisa ser igual ou menor que a data fim do ano letivo da escola ou turma (15/12/2030).');

    LegacyInstitution::query()->whereKey($this->scenario->institution)->update(['data_fechamento' => '2000-11-30']);
    matricular(['data' => '01/12/2030'])->assertUnprocessable()
        ->assertJsonPath('message', 'Não é possível matricular alunos após a data de fechamento.');

    matricular(['data' => '2030/02/10'])->assertUnprocessable()
        ->assertJsonPath('message', 'Informe a data no formato dd/mm/aaaa.');

    expect(LegacyRegistration::query()->where('ref_cod_aluno', $this->aluno->getKey())->where('ano', 2030)->exists())->toBeFalse();
});

test('recusa série fora da sequência quando a instituição bloqueia', function () {
    LegacyInstitution::query()->whereKey($this->scenario->institution)->update(['bloqueia_matricula_serie_nao_seguinte' => true]);
    $this->turma = $this->scenario->schoolClass(['nm_turma' => '1º ano A']);

    matricular()->assertUnprocessable()
        ->assertJsonPath('message', 'Não é possível matricular alunos em séries fora da sequência de enturmação.');
});

test('avisa da faixa etária e matricula depois que a pessoa confirma', function () {
    LegacyInstitution::query()->whereKey($this->scenario->institution)->update(['data_base_matricula' => '2030-03-31']);
    $this->segundoAno->update(['alerta_faixa_etaria' => true, 'idade_inicial' => 7, 'idade_final' => 8]);
    $this->aluno = LegacyStudentFactory::new()->create([
        'ref_idpes' => LegacyIndividualFactory::new()->create(['idpes' => LegacyPersonFactory::new()->create(['nome' => 'Mais Velho', 'tipo' => 'F']), 'data_nasc' => '2015-01-01']),
    ]);

    matricular()->assertUnprocessable()->assertJsonPath('precisaConfirmar', true);
    matricular(['confirmarFaixaEtaria' => true])->assertCreated();
});

test('usuário sem permissão de matrícula recebe 403', function () {
    $semPermissao = LegacyUserFactory::new()->school()->withSchool($this->scenario->school)->create();

    $this->actingAs($semPermissao)->getJson('/api/app/matriculas/turmas?aluno=' . $this->aluno->getKey())->assertForbidden();
    $this->actingAs($semPermissao)->postJson('/api/app/matriculas', ['aluno' => $this->aluno->getKey(), 'turma' => $this->turma->getKey(), 'data' => '10/02/2030'])->assertForbidden();
});

test('usuário de escola não matricula em turma de outra escola', function () {
    $deOutraEscola = LegacyUserFactory::new()->school()->withSchool(LegacySchoolFactory::new()->create())->create();

    foreach ([578, 680] as $processo) {
        LegacyMenuUserTypeFactory::new()->create([
            'ref_cod_tipo_usuario' => $deOutraEscola->ref_cod_tipo_usuario,
            'menu_id' => MenuFactory::new()->create(['process' => $processo, 'parent_id' => null]),
        ]);
    }

    $this->actingAs($deOutraEscola)->postJson('/api/app/matriculas', ['aluno' => $this->aluno->getKey(), 'turma' => $this->turma->getKey(), 'data' => '10/02/2030'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Esta turma não é de uma das suas escolas.');
});

test('quem volta de transferência na mesma série fica com a situação travada até o job liberar, como na tela antiga', function () {
    Bus::fake([UpdateBlockedSituationRegistration::class]);
    $this->scenario->evaluationRule([], $this->segundoAno);
    $this->scenario->discipline('Matemática', grade: $this->segundoAno);
    $this->scenario->registration(['ref_cod_aluno' => $this->aluno, 'ref_ref_cod_serie' => $this->segundoAno, 'aprovado' => RegistrationStatus::TRANSFERRED]);

    $resposta = matricular()->assertCreated();

    expect(LegacyRegistration::query()->find($resposta->json('matricula'))->bloquear_troca_de_situacao)->toBeTrue();
    Bus::assertDispatched(UpdateBlockedSituationRegistration::class);
});
