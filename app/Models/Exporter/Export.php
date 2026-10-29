<?php

namespace App\Models\Exporter;

use App\Exports\EloquentExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property array<int, string> $fillable
 * @property Model $model
 * @property array<int, string>  $fields
 * @property array<int, array>  $filters
 */
class Export extends Model
{
    protected $table = 'export';

    protected $fillable = [
        'user_id',
        'model',
        'fields',
        'url',
        'hash',
        'filename',
        'filters',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'fields' => 'json',
        'filters' => 'json',
    ];

    /**
     * @return array<int, Model>
     */
    public function getAllowedExports()
    {
        return [
            1 => new Enrollment,
            2 => new Student,
            3 => new Teacher,
            4 => new SocialAssistance,
            5 => new Stage,
            6 => new Employee,
        ];
    }

    /**
     * @param int $code
     * @return mixed
     */
    public function getExportByCode($code)
    {
        return $this->getAllowedExports()[$code] ?? new Student;
    }

    /**
     * @return EloquentExporter
     */
    public function getExporter()
    {
        return new EloquentExporter($this);
    }

    /**
     * @return Model
     */
    public function newExportModel()
    {
        $model = $this->model;

        $allowed = collect($this->getAllowedExports())->map(fn ($export) => $export::class)->all();

        if (!in_array($model, $allowed, true)) {
            throw new \InvalidArgumentException('Tipo de exportação inválido.');
        }

        return new $model;
    }

    /**
     * @return Builder<Model>
     */
    public function newExportQueryBuilder()
    {
        return $this->newExportModel()->newQuery();
    }

    /**
     * @return array<int, string>
     */
    private function allowedRelationFields(): array
    {
        $model = $this->newExportModel();

        if (!method_exists($model, 'getExportedColumnsByGroup')) {
            return [];
        }

        return collect($model->getExportedColumnsByGroup())
            ->flatMap(fn ($columns) => array_keys($columns))
            ->filter(fn ($field) => str_contains($field, '.'))
            ->values()
            ->all();
    }

    /**
     * @return Builder<Model>
     */
    public function getExportQuery()
    {
        $select = [];
        $relations = [];

        foreach ($this->fields as $field) {
            if (!Str::contains($field, '.')) {
                $select[] = $field;

                continue;
            }

            [$relation, $column] = explode('.', $field, 2);

            // O nome da relação vira uma chamada de método no builder, então só
            // aceita as relações que o modelo declara como exportáveis.
            if (!in_array($field, $this->allowedRelationFields(), true)) {
                continue;
            }

            $relations[$relation][] = $column;
        }

        $query = $this->newExportQueryBuilder()->select($select);

        foreach ($relations as $relation => $columns) {
            $query->{$relation}($columns);
        }

        /** @var Builder<Model> $query */
        $this->applyFilters($query);

        return $query;
    }

    /**
     * @param Builder<Model> $query
     */
    public function applyFilters(Builder $query): void
    {
        foreach ($this->filters ?? [] as $filter) {
            $column = $filter['column'];
            $operator = $filter['operator'];
            $value = $filter['value'];

            // A coluna vem do próprio sistema, mas entra crua na consulta.
            if (!preg_match('/^[a-z_]+\.[a-z_]+$/', (string) $column)) {
                continue;
            }

            switch ($operator) {
                case '=':
                    $query->where($column, '=', $value);

                    break;

                case 'in':
                    $query->whereIn($column, (array) $value);

                    break;
                case '@>':
                    if (is_array($value)) {
                        $query->where(function ($q) use ($column, $value) {
                            foreach ($value as $v) {
                                $q->orWhereRaw("{$column} @> ?", ['{' . (int) $v . '}']);
                            }
                        });
                    } else {
                        $query->whereRaw("{$column} @> ?", ['{' . (int) $value . '}']);
                    }

                    break;
            }
        }
    }
}
