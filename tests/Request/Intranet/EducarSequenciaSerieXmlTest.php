<?php

use Database\Factories\LegacyGradeFactory;
use Database\Factories\LegacySchoolGradeFactory;
use Database\Factories\LegacyUserFactory;

beforeEach(function () {
    $this->actingAs(LegacyUserFactory::new()->admin()->create());

    $this->schoolGrade = LegacySchoolGradeFactory::new()->create();
    $this->other = LegacyGradeFactory::new()->create(['ref_cod_curso' => $this->schoolGrade->grade->ref_cod_curso]);

    $this->xml = fn (array $query) => $this->get('/intranet/educar_sequencia_serie_xml.php?' . http_build_query($query))->assertOk();
    $this->query = [
        'cur' => $this->schoolGrade->grade->ref_cod_curso,
        'ser_dif' => $this->other->getKey(),
        'escola' => $this->schoolGrade->ref_cod_escola,
    ];
});

test('lista as séries do curso oferecidas pela escola no ano', function () {
    ($this->xml)([...$this->query, 'ano' => now()->year])
        ->assertSee('<serie cod_serie="' . $this->schoolGrade->ref_cod_serie . '">', false);
});

test('escola ou ano com sql injetado devolvem a consulta vazia', function () {
    ($this->xml)([...$this->query, 'ano' => now()->year . ' OR TRUE'])
        ->assertDontSee('<serie ', false);

    ($this->xml)([...$this->query, 'escola' => '0 OR TRUE', 'ano' => now()->year])
        ->assertDontSee('<serie ', false);
});
