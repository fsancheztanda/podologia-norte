<?php
// app/Services/AvailabilityService.php
//
// Lógica central de disponibilidad de slots.
// Maneja timezone, feriados, días bloqueados y concurrencia.

namespace App\Services;

use App\Models\Appointment;
use App\Models\BlockedDay;
use App\Models\Holiday;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class AvailabilityService
{
    private const TIMEZONE = 'America/Argentina/Buenos_Aires';

    // ----------------------------------------------------------
    // Retorna todos los slots disponibles para una profesional
    // en una fecha dada.
    //
    // Formato de respuesta:
    // [
    //   ['time' => '09:00', 'available' => true],
    //   ['time' => '10:00', 'available' => false],
    //   ...
    // ]
    // ----------------------------------------------------------
    public function getSlotsForDate(User $professional, string $date): array
    {
        $localDate = Carbon::parse($date, self::TIMEZONE)->startOfDay();
        $dayKey    = strtolower($localDate->format('D')); // mon, tue, etc.

        // Validaciones previas: ¿trabaja ese día?
        if (!$this->isWorkingDay($professional, $localDate, $dayKey)) {
            return [];
        }

        // Horario de trabajo del profesional para ese día
        $hours = $professional->hoursFor($dayKey);
        [$startTime, $endTime] = $hours;

        $slotStart    = Carbon::parse($localDate->toDateString() . ' ' . $startTime, self::TIMEZONE);
        $slotEnd      = Carbon::parse($localDate->toDateString() . ' ' . $endTime, self::TIMEZONE);
        $durationMins = $professional->appointment_duration_min;

        // Traer todos los turnos existentes para esa profesional ese día
        // (se usa para marcar slots como ocupados)
        $existingAppointments = $this->getExistingAppointments($professional, $localDate);

        $slots = [];
        $current = $slotStart->copy();

        while ($current->copy()->addMinutes($durationMins)->lte($slotEnd)) {
            $slotEndTime = $current->copy()->addMinutes($durationMins);

            $slots[] = [
                'time'      => $current->format('H:i'),
                'datetime'  => $current->toIso8601String(),
                'available' => !$this->isSlotTaken($current, $slotEndTime, $existingAppointments)
                               && $current->isFuture(), // ahora usa el timezone local correctamente
            ];

            $current->addMinutes($durationMins);
        }

        return $slots;
    }

    // ----------------------------------------------------------
    // Verifica si un slot específico está disponible.
    // Usado antes de confirmar una reserva (segunda validación).
    // ----------------------------------------------------------
    public function isSlotAvailable(User $professional, Carbon $startsAt, Carbon $endsAt): bool
    {
        $localDate = $startsAt->copy()->setTimezone(self::TIMEZONE)->startOfDay();
        $dayKey    = strtolower($localDate->format('D'));

        if (!$this->isWorkingDay($professional, $localDate, $dayKey)) {
            return false;
        }

        $existingAppointments = $this->getExistingAppointments($professional, $localDate);

        return !$this->isSlotTaken($startsAt, $endsAt, $existingAppointments);
    }

    // ----------------------------------------------------------
    // PRIVADOS
    // ----------------------------------------------------------

    private function isWorkingDay(User $professional, Carbon $date, string $dayKey): bool
    {
        // ¿La profesional trabaja ese día de la semana?
        if (!$professional->worksOn($dayKey)) {
            return false;
        }

        // ¿Es un día bloqueado manualmente?
        if (BlockedDay::where('date', $date->toDateString())->exists()) {
            return false;
        }

        // ¿Es feriado no laborable?
        $holiday = Holiday::find($date->toDateString());
        if ($holiday && !$holiday->is_working_day) {
            return false;
        }

        return true;
    }

    private function getExistingAppointments(User $professional, Carbon $date): Collection
    {
        // Forzar la fecha al inicio del día en el timezone de Argentina, luego pasar a UTC
        $utcStart = Carbon::parse($date->toDateString() . ' 00:00:00', self::TIMEZONE)->utc();
        $utcEnd   = Carbon::parse($date->toDateString() . ' 23:59:59', self::TIMEZONE)->utc();

        return Appointment::where('user_id', $professional->id)
            ->whereIn('status', [
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_RESCHEDULED,
                Appointment::STATUS_ATTENDED,
                Appointment::STATUS_BLOCKED,
            ])
            ->where('starts_at', '>=', $utcStart)
            ->where('starts_at', '<=', $utcEnd)
            ->get(['starts_at', 'ends_at']);
    }

    private function isSlotTaken(Carbon $slotStart, Carbon $slotEnd, Collection $appointments): bool
    {
        foreach ($appointments as $appt) {
            // Verificar solapamiento: dos rangos se superponen si uno empieza antes de que el otro termine
            if ($slotStart->lt($appt->ends_at) && $slotEnd->gt($appt->starts_at)) {
                return true;
            }
        }
        return false;
    }
}
