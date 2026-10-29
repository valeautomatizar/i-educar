<?php

header('Content-type: text/xml');

Portabilis_Utils_DeprecatedXmlApi::returnEmptyQueryUnlessUserIsLoggedIn();

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<query xmlns=\"sugestoes\">\n";
if (is_numeric($_GET['cur']) && is_numeric($_GET['ser_dif']) && is_numeric($_GET['escola'] ?? null) && is_numeric($_GET['ano'] ?? null)) {
    $db = new clsBanco;
    $consulta = 'SELECT cod_serie, nm_serie
                    FROM pmieducar.serie s
                    INNER JOIN pmieducar.escola_serie es ON es.ref_cod_serie = s.cod_serie
                   WHERE ref_cod_curso = $1
                    AND s.ativo = 1
                    AND cod_serie <> $2
                    AND es.ref_cod_escola = $3
                    AND CAST($4 AS smallint) = ANY (es.anos_letivos)
                    ORDER BY nm_serie';
    $db->execPreparedQuery($consulta, [(int) $_GET['cur'], (int) $_GET['ser_dif'], (int) $_GET['escola'], (int) $_GET['ano']]);
    while ($db->ProximoRegistro()) {
        [$serie, $nm_serie] = $db->Tupla();
        echo "  <serie cod_serie=\"$serie\">" . htmlspecialchars((string) $nm_serie, ENT_XML1, 'UTF-8') . "</serie>\n";
    }
}
echo '</query>';
