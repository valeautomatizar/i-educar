<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Habilidade (EF) ou objetivo de aprendizagem (EI) da BNCC.
 *
 * @property string $code
 * @property string $stage
 * @property string $component
 * @property array<int, int> $years
 * @property string|null $age_group
 * @property string|null $knowledge_objects
 * @property string $description
 */
class BnccSkill extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $hidden = ['search_text', 'search_vector'];

    protected function casts(): array
    {
        return [
            'years' => 'array',
        ];
    }

    /** Texto sem acentos, em minúsculas, com espaços simples. */
    public static function normalizar(string $texto): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($texto))));
    }

    /**
     * Palavras de busca: qualquer uma delas serve, e as habilidades com mais
     * palavras em comum aparecem antes. Palavras curtas ficam de fora.
     *
     * @return array<int, string>
     */
    public static function palavras(string $texto): array
    {
        preg_match_all('/[a-z0-9]{3,}/', self::normalizar($texto), $achadas);

        return array_slice(array_values(array_unique($achadas[0])), 0, 40);
    }

    /**
     * Ordena pela relevância das palavras (as que não batem nenhuma ficam de
     * fora), dando preferência ao componente e ao ano da turma.
     */
    public function scopeSugeridas(Builder $query, array $palavras, ?string $componente, ?int $ano, ?string $etapa): Builder
    {
        $consulta = implode(' | ', $palavras);
        $componenteNormalizado = $componente ? self::normalizar($componente) : null;

        $query->select('bncc_skills.*')
            ->when($etapa, fn ($q) => $q->where('stage', $etapa));

        if ($palavras) {
            $query->whereRaw("search_vector @@ to_tsquery('portuguese', ?)", [$consulta])
                ->selectRaw("ts_rank(search_vector, to_tsquery('portuguese', ?)) AS relevancia", [$consulta]);
        } else {
            $query->selectRaw('0 AS relevancia');
        }

        return $query
            ->orderByRaw(
                '(CASE WHEN ?::text IS NOT NULL AND lower(unaccent(component)) = ?::text THEN 1 ELSE 0 END)'
                . ' + (CASE WHEN ?::int IS NOT NULL AND years @> to_jsonb(?::int) THEN 0.5 ELSE 0 END) DESC',
                [$componenteNormalizado, $componenteNormalizado, $ano, $ano]
            )
            ->orderByDesc('relevancia')
            ->orderBy('code');
    }
}
