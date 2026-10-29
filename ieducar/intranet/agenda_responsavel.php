<?php

use Illuminate\Support\Facades\DB;

return new class extends clsListagem
{
    public $cd_agenda;

    public $nm_agenda;

    public function Gerar()
    {
        $this->pessoa = $this->pessoa_logada;

        $this->titulo = 'Agendas que eu posso editar';

        $this->addCabecalhos(coluna: ['Agenda']);

        $this->campoTexto(nome: 'pesquisa', campo: 'Agenda', valor: '', tamanhovisivel: 50, tamanhomaximo: 255);

        $pessoa = (int) $this->pessoa;
        $pesquisa = null;

        if (!empty($_GET['pesquisa'])) {
            $pesquisa = '%' . str_replace(search: ' ', replace: '%', subject: (string) $_GET['pesquisa']) . '%';
        }

        $total = DB::selectOne(
            'SELECT COUNT(0) + (SELECT COUNT(*) FROM portal.agenda_responsavel WHERE ref_ref_cod_pessoa_fj = ?) AS total FROM portal.agenda WHERE ref_ref_cod_pessoa_own = ? AND (CAST(? AS text) IS NULL OR nm_agenda ILIKE ?)',
            [$pessoa, $pessoa, $pesquisa, $pesquisa]
        )->total;

        // Paginador
        $limite = 15;
        $sql = 'SELECT cod_agenda, 1 AS minha FROM agenda WHERE ref_ref_cod_pessoa_own = $1 AND (CAST($2 AS text) IS NULL OR nm_agenda ILIKE $3) UNION SELECT ref_cod_agenda, 0 AS minha FROM agenda_responsavel WHERE ref_ref_cod_pessoa_fj = $4 ORDER BY minha DESC';

        $db1 = new clsBanco;
        $db1->execPreparedQuery($sql, [$pessoa, $pesquisa, $pesquisa, $pessoa]);
        while ($db1->ProximoRegistro()) {
            [$cd_agenda, $propriedade] = $db1->Tupla();

            $db2 = new clsBanco;
            $db2->execPreparedQuery('SELECT nm_agenda, ref_ref_cod_pessoa_own FROM agenda WHERE cod_agenda = $1 AND (CAST($2 AS text) IS NULL OR nm_agenda ILIKE $3)', [(int) $cd_agenda, $pesquisa, $pesquisa]);
            while ($db2->ProximoRegistro()) {
                [$nm_agenda, $cod_pessoa_own] = $db2->Tupla();
                $this->addLinhas(linha: [
                    "<a href='agenda.php?cod_agenda={$cd_agenda}'><img src='imagens/noticia.jpg' border=0>$nm_agenda</a>"]);
            }
        }

        // Paginador
        $this->addPaginador2(strUrl: 'agenda_responsavel.php', intTotalRegistros: $total, mixVariaveisMantidas: $_GET, nome: $this->nome, intResultadosPorPagina: $limite);

        $this->largura = '100%';

        $this->breadcrumb(currentPage: 'Agendas');
    }

    public function Formular()
    {
        $this->title = 'Agenda';
        $this->processoAp = '341';
    }
};
