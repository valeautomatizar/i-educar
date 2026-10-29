<?php

use Database\Factories\LegacyDisciplineFactory;
use Illuminate\Support\Facades\DB;
use Tests\Request\Support\LegacyApiScenario;

beforeEach(function () {
    LegacyApiScenario::create($this);
});

LegacyApiScenario::registerResourceTests('/module/Api/ComponentesSerie', [
    'existe-dispensa',
    'existe-dependencia',
    'get-escolas-by-serie',
]);

test('os anos letivos do componente viram números antes de entrar na consulta', function () {
    $discipline = LegacyDisciplineFactory::new()->create();
    $serieId = $this->schoolClass->ref_ref_cod_serie;

    $this->post('/module/Api/ComponentesSerie', [
        'oper' => 'post',
        'resource' => 'atualiza-componentes-serie',
        'serie_id' => $serieId,
        'componentes' => json_encode([[
            'id' => $discipline->getKey(),
            'carga_horaria' => 80,
            'hora_falta' => null,
            'tipo_nota' => 1,
            'anos_letivos' => ['2030', "2031}'::smallint[] || '{1"],
        ]]),
    ] + $this->params)->assertOk();

    $anos = DB::selectOne(
        'SELECT array_to_json(anos_letivos) AS anos FROM modules.componente_curricular_ano_escolar WHERE ano_escolar_id = ? AND componente_curricular_id = ?',
        [$serieId, $discipline->getKey()]
    )->anos;

    expect(json_decode($anos))->toBe([2030, 2031]);
});
