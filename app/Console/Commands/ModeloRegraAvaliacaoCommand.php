<?php

namespace App\Console\Commands;

use App\Services\ModelosDeRegraDeAvaliacao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ModeloRegraAvaliacaoCommand extends Command
{
    protected $signature = 'regra-avaliacao:modelo
        {estado : Modelo do estado, por exemplo: pernambuco}
        {--instituicao= : Código da instituição (padrão: a primeira ativa)}
        {--ano= : Ano letivo para ligar as séries (padrão: o ano atual)}
        {--nota= : Séries (códigos separados por vírgula) que usam a regra de nota}
        {--parecer= : Séries que usam a regra de parecer (1º e 2º ano)}
        {--infantil= : Séries que usam a regra da Educação Infantil}';

    protected $description = 'Cria ou atualiza as regras de avaliação prontas de um estado e liga as séries a elas';

    public function handle(ModelosDeRegraDeAvaliacao $modelos): int
    {
        $instituicao = (int) ($this->option('instituicao')
            ?: DB::table('pmieducar.instituicao')->where('ativo', 1)->orderBy('cod_instituicao')->value('cod_instituicao'));

        if (!$instituicao) {
            $this->error('Nenhuma instituição ativa encontrada.');

            return self::FAILURE;
        }

        try {
            $modelo = $modelos->modelo($this->argument('estado'));
            $regras = $modelos->aplicar($this->argument('estado'), $instituicao);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Modelo {$modelo['nome']} carregado na instituição {$instituicao}.");
        foreach ($regras as $grupo => $id) {
            $this->line("  {$modelo['regras'][$grupo]['nome']}: regra {$id}");
        }

        $ano = (int) ($this->option('ano') ?: date('Y'));
        foreach (['nota', 'parecer', 'infantil'] as $grupo) {
            $series = array_filter(array_map('intval', explode(',', (string) $this->option($grupo))));
            if ($series) {
                $modelos->vincular($regras[$grupo], $series, $ano);
                $this->line('  Séries ' . implode(', ', $series) . " ligadas a \"{$modelo['regras'][$grupo]['nome']}\" em {$ano}.");
            }
        }

        $this->line("Lembre: o calendário das escolas precisa ter {$modelo['etapas']} etapas no ano.");

        return self::SUCCESS;
    }
}
