<?php

namespace App\Http\Controllers\Api\App;

use App\Models\RegistrationStatus;

/**
 * Traduz a situação da matrícula do i-Educar para os poucos grupos que a
 * interface nova mostra.
 */
final class SituacaoMatricula
{
    public static function grupo(int $aprovado): string
    {
        return match ($aprovado) {
            RegistrationStatus::ONGOING => 'cursando',
            RegistrationStatus::APPROVED,
            RegistrationStatus::APPROVED_PAST_EXAM,
            RegistrationStatus::APPROVED_WITHOUT_EXAM,
            RegistrationStatus::APPROVED_WITH_DEPENDENCY,
            RegistrationStatus::APPROVED_BY_BOARD => 'aprovado',
            RegistrationStatus::REPROVED,
            RegistrationStatus::REPROVED_BY_ABSENCE => 'reprovado',
            RegistrationStatus::TRANSFERRED => 'transferido',
            RegistrationStatus::ABANDONED => 'abandono',
            default => 'outra',
        };
    }

    public static function texto(int $aprovado): string
    {
        return (new RegistrationStatus)->getDescriptiveValues()[$aprovado] ?? 'Sem situação';
    }
}
