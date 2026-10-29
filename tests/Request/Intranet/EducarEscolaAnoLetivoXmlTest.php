<?php

use Database\Factories\LegacySchoolAcademicYearFactory;
use Database\Factories\LegacySchoolFactory;
use Database\Factories\LegacyUserFactory;

beforeEach(function () {
    $this->actingAs(LegacyUserFactory::new()->admin()->create());

    $this->school = LegacySchoolFactory::new()->create();
    foreach ([2020, 2021, 2022] as $year) {
        LegacySchoolAcademicYearFactory::new()->create(['ref_cod_escola' => $this->school, 'ano' => $year]);
    }

    $this->xml = fn (array $query) => $this->get('/intranet/educar_escola_ano_letivo_xml.php?' . http_build_query($query))->assertOk();
});

test('lista os anos letivos a partir do ano informado', function () {
    ($this->xml)(['esc' => $this->school->getKey(), 'ano_atual' => 2021])
        ->assertDontSee('<ano>2020</ano>', false)
        ->assertSee('<ano>2021</ano>', false)
        ->assertSee('<ano>2022</ano>', false);
});

test('ano com sql injetado é ignorado e não chega ao banco', function () {
    ($this->xml)(['esc' => $this->school->getKey(), 'ano_atual' => '0 OR 1=1; SELECT 1'])
        ->assertSee('<ano>2020</ano>', false);
});
