<?php

namespace App\Models;

use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Anotação do professor sobre a turma (student_id nulo) ou sobre um aluno.
 *
 * @property int $id
 * @property int $school_class_id
 * @property int|null $student_id
 * @property int $user_id
 * @property Carbon $date
 * @property string $text
 */
class ClassNote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'school_class_id',
        'student_id',
        'user_id',
        'date',
        'text',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(LegacySchoolClass::class, 'school_class_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(LegacyStudent::class, 'student_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
