<?php

use Database\Factories\LegacySchoolAcademicYearFactory;
use Database\Factories\LegacySchoolClassFactory;
use Database\Factories\LegacySchoolGradeFactory;
use Database\Factories\LegacyUserFactory;

beforeEach(function () {
    $this->actingAs(LegacyUserFactory::new()->admin()->create());

    $this->schoolGrade = LegacySchoolGradeFactory::new()->create();
    LegacySchoolAcademicYearFactory::new()->create(['ref_cod_escola' => $this->schoolGrade->ref_cod_escola, 'ano' => now()->year]);
    $this->schoolClass = LegacySchoolClassFactory::new()->create([
        'ref_ref_cod_escola' => $this->schoolGrade->ref_cod_escola,
        'ref_ref_cod_serie' => $this->schoolGrade->ref_cod_serie,
        'ref_cod_curso' => $this->schoolGrade->grade->ref_cod_curso,
        'nm_turma' => 'Turma <A&B>',
        'ano' => now()->year,
    ]);

    $this->xml = fn (array $query) => $this->get('/intranet/educar_turma_xml.php?' . http_build_query($query))->assertOk();
    $this->query = ['esc' => $this->schoolGrade->ref_cod_escola, 'ser' => $this->schoolGrade->ref_cod_serie];
});

test('lista as turmas da escola e série no ano informado', function () {
    ($this->xml)([...$this->query, 'ano' => now()->year])
        ->assertSee('<turma cod_turma="' . $this->schoolClass->getKey() . '">', false);
});

test('não lista as turmas de outro ano', function () {
    ($this->xml)([...$this->query, 'ano' => now()->year - 10])
        ->assertDontSee('<turma ', false);
});

test('escapa o nome da turma para não quebrar o xml', function () {
    ($this->xml)($this->query)
        ->assertSee('Turma &lt;A&amp;B&gt;', false)
        ->assertDontSee('<A&B>', false);
});

test('ano com sql injetado é ignorado e não chega ao banco', function () {
    ($this->xml)([...$this->query, 'ano' => '0) OR (SELECT pg_sleep(0)) IS NULL OR (1'])
        ->assertSee('<turma cod_turma="' . $this->schoolClass->getKey() . '">', false);
});
