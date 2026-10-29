<?php

use App\Models\Exporter\Enrollment;
use App\Models\Exporter\Student;
use App\Models\Exporter\Teacher;
use App\Models\LegacyEmployee;
use Database\Factories\Exporter\ExportFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

test('monta a consulta da exportação com as relações pedidas e conta os registros', function () {
    $relatives = ['cpf', 'phone', 'religion', 'race', 'birthplace', 'marital_status', 'person_schooling_degree'];
    $fields = ['registration_id', 'transport.tipo_transporte', 'transport.veiculo_transporte_escolar'];
    foreach (['mother', 'father', 'guardian'] as $relative) {
        foreach ($relatives as $column) {
            $fields[] = "{$relative}.{$column}";
        }
    }

    $export = ExportFactory::new()->create(['model' => Enrollment::class, 'fields' => $fields, 'filters' => [
        ['column' => 'exporter_student.year', 'operator' => '=', 'value' => 2030],
        ['column' => 'exporter_student.school_id', 'operator' => 'in', 'value' => [1, 2]],
    ]]);

    $exporter = $export->getExporter();

    expect($exporter->query())
        ->toContain('"fisica" as "mf"', '"fisica" as "ff"', '"fisica" as "gf"', '"aluno" as "aluno"', 'tipo_transporte', '"exporter_student"."year" = ?', '"exporter_student"."school_id" in (?, ?)')
        ->and($export->getExportQuery()->getBindings())->toBe([2030, 1, 2])
        ->and($exporter->getExportCount())->toBe(0);
});

test('os filtros de escola por lista de matrículas viram contenção de array, uma por escola', function () {
    $export = ExportFactory::new()->create(['model' => Student::class, 'fields' => ['student_id'], 'filters' => [
        ['column' => 'exporter_student_grouped_registration.school_filter_id', 'operator' => '@>', 'value' => [1, 2]],
        ['column' => 'exporter_student_grouped_registration.school_filter_id', 'operator' => '@>', 'value' => 3],
    ]]);

    $query = $export->getExportQuery();

    expect($query->toSql())
        ->toContain('(exporter_student_grouped_registration.school_filter_id @> ? or exporter_student_grouped_registration.school_filter_id @> ?)', 'school_filter_id @> ?')
        ->and($query->getBindings())->toBe(['{1}', '{2}', '{3}'])
        ->and($query->count())->toBe(0);
});

test('os valores dos filtros vão como parâmetros e não entram na consulta', function () {
    $injection = '1) OR 1=1; DELETE FROM pmieducar.aluno; --';

    $export = ExportFactory::new()->create(['model' => Student::class, 'fields' => ['student_id'], 'filters' => [
        ['column' => 'exporter_student_grouped_registration.status', 'operator' => '=', 'value' => $injection],
        ['column' => 'exporter_student_grouped_registration.school_id', 'operator' => 'in', 'value' => [$injection]],
        ['column' => 'exporter_student_grouped_registration.school_filter_id', 'operator' => '@>', 'value' => $injection],
    ]]);

    $query = $export->getExportQuery();

    expect($query->toSql())->not->toContain('DELETE')
        ->and($query->getBindings())->toBe([$injection, $injection, '{1}']);
});

test('ignora filtro com coluna fora do padrão e relação que o modelo não exporta', function () {
    $export = ExportFactory::new()->create(['model' => Student::class, 'fields' => ['student_id', 'delete.x'], 'filters' => [
        ['column' => 'status = 1 OR 1=1 --', 'operator' => '=', 'value' => 1],
    ]]);

    expect($export->getExportQuery()->toSql())->not->toContain('1=1')->not->toContain('delete');
});

test('não monta exportação de modelo que não está na lista permitida', function () {
    $export = ExportFactory::new()->make(['model' => LegacyEmployee::class, 'fields' => ['senha']]);

    expect(fn () => $export->getExportQuery())->toThrow(InvalidArgumentException::class);
});

test('monta a consulta dos professores com as alocações', function () {
    $export = ExportFactory::new()->create(['model' => Teacher::class, 'fields' => ['id', 'allocations.funcao_exercida', 'allocations.tipo_vinculo'], 'filename' => 'professores.csv']);

    expect($export->getExportQuery()->toSql())
        ->toContain('"professor_turma" as "allocations"', 'Função Exercida', 'Tipo Vínculo');
});
