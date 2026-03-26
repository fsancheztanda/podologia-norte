<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    /**
     * Solo admin puede ver datos sensibles / financieros
     */
    public function viewFinancials(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Solo admin gestiona feriados
     */
    public function manageHolidays(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Admin puede todo; podóloga solo puede actualizar sus propios turnos
     */
    public function update(User $user, Appointment $appointment): bool
    {
        return $user->isAdmin() || $user->id === $appointment->user_id;
    }
}
