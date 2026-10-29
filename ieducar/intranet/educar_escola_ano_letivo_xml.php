<?php

header('Content-type: text/xml');

Portabilis_Utils_DeprecatedXmlApi::returnEmptyQueryUnlessUserIsLoggedIn();

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<query xmlns=\"sugestoes\">\n";
if (is_numeric($_GET['esc'])) {
    $db = new clsBanco;
    $anoAtual = is_numeric($_GET['ano_atual'] ?? null) ? (int) $_GET['ano_atual'] : null;
    $limite = !empty($_GET['lim']) ? 'LIMIT 5' : '';

    $db->execPreparedQuery("
                SELECT
                    ano
                FROM
                    pmieducar.escola_ano_letivo
                WHERE
                    ref_cod_escola = $1
                    AND ano >= COALESCE(CAST($2 AS integer), ano)
                    AND ativo = 1
                ORDER BY
                    ano asc {$limite}
                ", [(int) $_GET['esc'], $anoAtual]);
    while ($db->ProximoRegistro()) {
        [$ano] = $db->Tupla();
        echo "  <ano>{$ano}</ano>\n";
    }
}

echo '</query>';
