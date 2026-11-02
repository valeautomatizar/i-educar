<?php

namespace App\Services;

use App\Models\LegacyEvaluationRule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RegraAvaliacao_Model_Nota_TipoValor as TipoNota;
use RegraAvaliacao_Model_TipoParecerDescritivo as TipoParecer;
use RegraAvaliacao_Model_TipoPresenca as TipoPresenca;
use RegraAvaliacao_Model_TipoProgressao as TipoProgressao;
use TabelaArredondamento_Model_TipoArredondamentoMedia as TipoArredondamento;

/**
 * Regras de avaliação prontas por estado, para carregar na implantação de uma
 * rede. Cada modelo cria a tabela de arredondamento, as fórmulas e as regras
 * (uma por grupo de séries); depois cada série é ligada à regra do seu grupo.
 * Rodar de novo atualiza o que já existe, pelo nome.
 */
class ModelosDeRegraDeAvaliacao
{
    /**
     * Pernambuco: Instrução Normativa SEE/PE nº 008/2025.
     * Ano em 3 trimestres; nota de 0 a 10 em passos de 0,5, arredondando para
     * cima; média 6,0; recuperação paralela no fim de cada trimestre e
     * recuperação final, valendo sempre a maior nota; progressão parcial em até
     * 3 componentes (não vale no ano concluinte, que tem exame especial);
     * 1º e 2º ano e Educação Infantil com parecer descritivo.
     */
    private const MODELOS = [
        'pernambuco' => [
            'nome' => 'Pernambuco (IN SEE/PE 08/2025)',
            'etapas' => 3,
            // Concluinte (9º ano e 3º ano do médio) não fica em dependência.
            'reprova_dependencia_ano_concluinte' => true,
            'tabela' => [
                'nome' => 'PE - meio ponto, para cima',
                // Decimal da nota => [ação, casa decimal exata]
                'valores' => [
                    '1' => [TipoArredondamento::ARREDONDAR_PARA_NOTA_ESPECIFICA, 5],
                    '2' => [TipoArredondamento::ARREDONDAR_PARA_NOTA_ESPECIFICA, 5],
                    '3' => [TipoArredondamento::ARREDONDAR_PARA_NOTA_ESPECIFICA, 5],
                    '4' => [TipoArredondamento::ARREDONDAR_PARA_NOTA_ESPECIFICA, 5],
                    '6' => [TipoArredondamento::ARREDONDAR_PARA_NOTA_SUPERIOR, null],
                    '7' => [TipoArredondamento::ARREDONDAR_PARA_NOTA_SUPERIOR, null],
                    '8' => [TipoArredondamento::ARREDONDAR_PARA_NOTA_SUPERIOR, null],
                    '9' => [TipoArredondamento::ARREDONDAR_PARA_NOTA_SUPERIOR, null],
                ],
            ],
            'formulas' => [
                'media' => ['nome' => 'PE - média dos trimestres', 'formula' => 'Se / Et'],
                // Vale a maior entre a média do ano e a recuperação final.
                'recuperacao' => ['nome' => 'PE - maior entre média e recuperação', 'formula' => '( Se / Et ) > Rc ? ( Se / Et ) : Rc'],
            ],
            'regras' => [
                'nota' => [
                    'nome' => 'PE - nota (3º ano em diante)',
                    'tipo_nota' => TipoNota::NUMERICA,
                    'tipo_progressao' => TipoProgressao::NAO_CONTINUADA_MEDIA_PRESENCA,
                    'tipo_presenca' => TipoPresenca::GERAL,
                    'parecer_descritivo' => TipoParecer::NENHUM,
                    'media' => 6,
                    'media_recuperacao' => 6,
                    'porcentagem_presenca' => 75,
                    'nota_maxima_geral' => 10,
                    'nota_minima_geral' => 0,
                    'nota_maxima_exame_final' => 10,
                    'qtd_casas_decimais' => 1,
                    // Recuperação paralela em todo trimestre, para quem fica abaixo de 6,0;
                    // vale a maior entre a nota do trimestre e a da recuperação.
                    'tipo_recuperacao_paralela' => LegacyEvaluationRule::PARALLEL_REMEDIAL_PER_STAGE,
                    'media_recuperacao_paralela' => 6,
                    'tipo_calculo_recuperacao_paralela' => LegacyEvaluationRule::PARALLEL_REMEDIAL_REPLACE_SCORE,
                    'qtd_disciplinas_dependencia' => 3,
                    'formula_recuperacao' => true,
                ],
                'parecer' => [
                    'nome' => 'PE - parecer (1º e 2º ano)',
                    'tipo_nota' => TipoNota::NENHUM,
                    'tipo_progressao' => TipoProgressao::CONTINUADA,
                    'tipo_presenca' => TipoPresenca::GERAL,
                    'parecer_descritivo' => TipoParecer::ETAPA_GERAL,
                    'porcentagem_presenca' => 75,
                    'qtd_casas_decimais' => 1,
                ],
                'infantil' => [
                    'nome' => 'PE - Educação Infantil',
                    'tipo_nota' => TipoNota::NENHUM,
                    'tipo_progressao' => TipoProgressao::CONTINUADA,
                    'tipo_presenca' => TipoPresenca::GERAL,
                    'parecer_descritivo' => TipoParecer::ETAPA_GERAL,
                    'porcentagem_presenca' => 60,
                    'qtd_casas_decimais' => 1,
                ],
            ],
        ],
    ];

    /**
     * @return array<int, string>
     */
    public function disponiveis(): array
    {
        return array_keys(self::MODELOS);
    }

    public function modelo(string $estado): array
    {
        $estado = mb_strtolower(trim($estado));

        if (!isset(self::MODELOS[$estado])) {
            throw new InvalidArgumentException("Não há modelo de regra para \"{$estado}\". Modelos: " . implode(', ', $this->disponiveis()) . '.');
        }

        return self::MODELOS[$estado];
    }

    /**
     * Cria ou atualiza as regras do estado na instituição.
     *
     * @return array<string, int> grupo => id da regra (nota, parecer, infantil)
     */
    public function aplicar(string $estado, int $instituicao): array
    {
        $modelo = $this->modelo($estado);

        return DB::transaction(function () use ($modelo, $instituicao) {
            $tabela = $this->tabela($modelo['tabela'], $instituicao);
            $media = $this->formula($modelo['formulas']['media'], 1, $instituicao);
            $recuperacao = $this->formula($modelo['formulas']['recuperacao'], 2, $instituicao);

            DB::table('pmieducar.instituicao')
                ->where('cod_instituicao', $instituicao)
                ->update(['reprova_dependencia_ano_concluinte' => $modelo['reprova_dependencia_ano_concluinte']]);

            $ids = [];
            foreach ($modelo['regras'] as $grupo => $regra) {
                $usaRecuperacao = $regra['formula_recuperacao'] ?? false;
                unset($regra['formula_recuperacao']);

                $ids[$grupo] = $this->upsert('modules.regra_avaliacao', [
                    'instituicao_id' => $instituicao,
                    'nome' => $regra['nome'],
                ], $regra + [
                    'formula_media_id' => $media,
                    'formula_recuperacao_id' => $usaRecuperacao ? $recuperacao : null,
                    'tabela_arredondamento_id' => $tabela,
                ]);
            }

            return $ids;
        });
    }

    /**
     * Liga as séries à regra no ano letivo (troca a regra que já estiver lá).
     *
     * @param array<int> $series
     */
    public function vincular(int $regra, array $series, int $ano): void
    {
        foreach ($series as $serie) {
            DB::table('modules.regra_avaliacao_serie_ano')->updateOrInsert(
                ['serie_id' => $serie, 'ano_letivo' => $ano],
                ['regra_avaliacao_id' => $regra, 'updated_at' => now()],
            );
        }
    }

    private function tabela(array $tabela, int $instituicao): int
    {
        $id = $this->upsert('modules.tabela_arredondamento', [
            'instituicao_id' => $instituicao,
            'nome' => $tabela['nome'],
        ], [
            'tipo_nota' => TipoNota::NUMERICA,
            // Arredonda também a nota de cada trimestre, não só a média.
            'arredondar_nota' => 1,
        ]);

        DB::table('modules.tabela_arredondamento_valor')->where('tabela_arredondamento_id', $id)->delete();

        foreach ($tabela['valores'] as $decimal => [$acao, $casaDecimal]) {
            DB::table('modules.tabela_arredondamento_valor')->insert([
                'tabela_arredondamento_id' => $id,
                'nome' => (string) $decimal,
                'descricao' => $casaDecimal === null ? 'Sobe para o inteiro seguinte' : "Sobe para ,{$casaDecimal}",
                'acao' => $acao,
                'casa_decimal_exata' => $casaDecimal,
            ]);
        }

        return $id;
    }

    private function formula(array $formula, int $tipo, int $instituicao): int
    {
        return $this->upsert('modules.formula_media', [
            'instituicao_id' => $instituicao,
            'nome' => $formula['nome'],
        ], [
            'formula_media' => $formula['formula'],
            'tipo_formula' => $tipo,
        ]);
    }

    private function upsert(string $tabela, array $chave, array $valores): int
    {
        $existente = DB::table($tabela)->where($chave)->value('id');

        if ($existente) {
            DB::table($tabela)->where('id', $existente)->update($valores);

            return (int) $existente;
        }

        return (int) DB::table($tabela)->insertGetId($chave + $valores);
    }
}
