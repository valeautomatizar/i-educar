<?php

header(header: 'Content-type: text/xml');

Portabilis_Utils_DeprecatedXmlApi::returnEmptyQueryUnlessUserIsLoggedIn();

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<query xmlns=\"sugestoes\">\n";
if (is_numeric(value: $_GET['esc']) && is_numeric(value: $_GET['ser'])) {
    $anoLetivo = is_numeric(value: $_GET['ano'] ?? null) ? (int) $_GET['ano'] : null;

    $db = new clsBanco;

    $sql = "SELECT cod_turma,
                   nm_turma || ' - ' || turma.ano::varchar AS nm_turma
            FROM pmieducar.turma
                INNER JOIN pmieducar.escola_ano_letivo ON (escola_ano_letivo.ref_cod_escola = turma.ref_ref_cod_escola AND escola_ano_letivo.ano = turma.ano)
                LEFT JOIN pmieducar.turma_serie ON (turma_serie.turma_id = turma.cod_turma )
                LEFT JOIN pmieducar.serie ON (cod_serie = COALESCE (turma_serie.serie_id, turma.ref_ref_cod_serie ))
            WHERE ref_ref_cod_escola = $1
                AND COALESCE (serie_id, ref_ref_cod_serie) = $2
                AND turma.ativo = 1
                AND escola_ano_letivo.andamento = 1
                AND escola_ano_letivo.ano = COALESCE(CAST($3 AS integer), escola_ano_letivo.ano)
            GROUP BY cod_turma
            ORDER BY nm_turma";

    $db->execPreparedQuery(query: $sql, params: [(int) $_GET['esc'], (int) $_GET['ser'], $anoLetivo]);

    while ($db->ProximoRegistro()) {
        [$cod, $nome] = $db->Tupla();
        echo "  <turma cod_turma=\"{$cod}\">" . htmlspecialchars((string) $nome, ENT_XML1, 'UTF-8') . "</turma>\n";
    }
} elseif (is_numeric(value: $_GET['ins']) && is_numeric(value: $_GET['cur'])) {
    $db = new clsBanco;

    $sql = 'SELECT cod_turma,
                   nm_turma
            FROM pmieducar.turma
            WHERE ref_cod_instituicao = $1
                AND ref_cod_curso = $2
                AND ref_ref_cod_escola is null
                AND ref_ref_cod_serie is null
                AND ativo = 1
            ORDER BY nm_turma';

    $db->execPreparedQuery(query: $sql, params: [(int) $_GET['ins'], (int) $_GET['cur']]);

    while ($db->ProximoRegistro()) {
        [$cod, $nome] = $db->Tupla();
        echo "  <turma cod_turma=\"{$cod}\">" . htmlspecialchars((string) $nome, ENT_XML1, 'UTF-8') . "</turma>\n";
    }
}
echo '</query>';
