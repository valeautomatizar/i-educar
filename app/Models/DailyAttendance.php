<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Presença de um aluno num dia de aula, por componente ou no geral.
 *
 * @property int $school_class_id
 * @property int $registration_id
 * @property int|null $discipline_id
 * @property Carbon $date
 * @property int $stage
 * @property bool $present
 * @property int|null $user_id
 */
class DailyAttendance extends Model
{
    protected $fillable = [
        'school_class_id',
        'registration_id',
        'discipline_id',
        'date',
        'stage',
        'present',
        'user_id',
    ];

    protected $casts = [
        'date' => 'date',
        'present' => 'boolean',
        'stage' => 'integer',
    ];

    /**
     * @return BelongsTo<LegacyRegistration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(LegacyRegistration::class, 'registration_id');
    }

    /**
     * @return BelongsTo<LegacySchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(LegacySchoolClass::class, 'school_class_id');
    }
}
