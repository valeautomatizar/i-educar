<?php

namespace App\Models;

use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * O que o professor ensinou numa aula da turma.
 *
 * @property int $id
 * @property int $school_class_id
 * @property int|null $discipline_id
 * @property int $user_id
 * @property Carbon $date
 * @property string $content
 */
class LessonRecord extends Model
{
    protected $fillable = [
        'school_class_id',
        'discipline_id',
        'user_id',
        'date',
        'content',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
