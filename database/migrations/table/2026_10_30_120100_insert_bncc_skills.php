<?php

use App\Models\BnccSkill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Carrega as habilidades da BNCC de database/csvs/bncc/habilidades.csv
 * (fonte e licença no README da pasta).
 */
return new class extends Migration
{
    public function up(): void
    {
        $arquivo = fopen(database_path('csvs/bncc/habilidades.csv'), 'r');
        $colunas = fgetcsv($arquivo, escape: '');
        $lote = [];

        while (($linha = fgetcsv($arquivo, escape: '')) !== false) {
            $dados = array_combine($colunas, $linha);
            $anos = $dados['anos'] === '' ? [] : array_map('intval', explode(',', $dados['anos']));

            $lote[] = [
                'code' => $dados['codigo'],
                'stage' => $dados['etapa'],
                'component' => $dados['componente'],
                'years' => json_encode($anos),
                'age_group' => $dados['grupo_etario'] ?: null,
                'knowledge_objects' => $dados['objetos_conhecimento'] ?: null,
                'description' => $dados['texto'],
                'search_text' => BnccSkill::normalizar(implode(' ', [$dados['texto'], $dados['objetos_conhecimento'], $dados['componente']])),
            ];

            if (count($lote) === 500) {
                DB::table('bncc_skills')->upsert($lote, ['code']);
                $lote = [];
            }
        }

        if ($lote) {
            DB::table('bncc_skills')->upsert($lote, ['code']);
        }

        fclose($arquivo);
    }

    public function down(): void
    {
        DB::table('bncc_skills')->delete();
    }
};
