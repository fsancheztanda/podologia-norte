<?php
// app/Services/AppointmentService.php
//
// Orquesta la creación, cancelación y reprogramación de turnos.
// Toda la lógica de negocio vive acá, no en los Controllers.

namespace App\Services;

use App\Jobs\SendAppointmentConfirmation;
use App\Jobs\SendWhatsAppReminder;
use App\Jobs\SyncGoogleCalendar;
use App\Models\Appointment;
use App\Models\IncomeRecord;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppointmentService
{
    private const TIMEZONE = 'America/Argentina/Buenos_Aires';

    public function __construct(
        private AvailabilityService $availability
    ) {}

    // ----------------------------------------------------------
    // CREAR TURNO
    // Atomic: usa transacción + lockForUpdate para evitar race conditions.
    // Si dos usuarios intentan el mismo slot, uno gana el lock
    // y el otro recibe una excepción que el Controller convierte en 409.
    // ----------------------------------------------------------
    public function book(array $data): Appointment
    {
        return DB::transaction(function () use ($data) {

            // 1. Buscar o crear paciente
            $patient = $this->findOrCreatePatient($data);

            // 2. Resolver profesional y horario
            $professional = User::findOrFail($data['professional_id']);
            $startsAt     = Carbon::parse($data['datetime'], self::TIMEZONE)->utc();
            $endsAt       = $startsAt->copy()->addMinutes($professional->appointment_duration_min);

            // 3. Verificar disponibilidad CON LOCK para evitar race conditions.
            //    lockForUpdate() bloquea las filas hasta que la transacción termine.
            $conflict = Appointment::where('user_id', $professional->id)
                ->whereIn('status', [Appointment::STATUS_CONFIRMED, Appointment::STATUS_RESCHEDULED])
                ->where(function ($q) use ($startsAt, $endsAt) {
                    $q->where('starts_at', '<', $endsAt)
                      ->where('ends_at', '>', $startsAt);
                })
                ->lockForUpdate()
                ->first();

            if ($conflict) {
                throw new \RuntimeException('El slot ya fue tomado. Por favor elegí otro horario.', 409);
            }

            // 4. Leer tarifa actual (se guarda históricamente, nunca se recalcula)
            $fee = (float) Setting::get('appointment_fee', 40000);

            // 5. Generar token de cancelación seguro (HMAC-SHA256)
            $rawToken   = Str::random(32);
            $cancelToken = hash_hmac('sha256', $rawToken, config('app.cancel_token_secret', env('CANCEL_TOKEN_SECRET')));
            $tokenExpiry = now()->addDays((int) Setting::get('cancel_token_expiry_days', 30));

            // 6. Crear el turno
            $appointment = Appointment::create([
                'patient_id'              => $patient->id,
                'user_id'                 => $professional->id,
                'starts_at'               => $startsAt,
                'ends_at'                 => $endsAt,
                'status'                  => Appointment::STATUS_CONFIRMED,
                'fee_at_booking'          => $fee,
                'cancel_token'            => $cancelToken,
                'cancel_token_expires_at' => $tokenExpiry,
            ]);

            // 7. Disparar jobs asíncronos (no bloquean la respuesta)
            SendAppointmentConfirmation::dispatch($appointment, $cancelToken);
            SyncGoogleCalendar::dispatch($appointment, 'create');

            // Recordatorio WhatsApp: se encola para ejecutarse 24hs antes del turno
            $reminderHours = (int) Setting::get('reminder_hours_before', 24);
            SendWhatsAppReminder::dispatch($appointment)
                ->delay($startsAt->copy()->subHours($reminderHours));

            return $appointment->load(['patient', 'professional']);
        });
    }

    // ----------------------------------------------------------
    // CANCELAR TURNO (sin login, via token)
    // ----------------------------------------------------------
    public function cancel(Appointment $appointment): Appointment
    {
        if (!$appointment->isCancellable()) {
            throw new \RuntimeException('Este turno no puede cancelarse.', 422);
        }

        DB::transaction(function () use ($appointment) {
            $appointment->update(['status' => Appointment::STATUS_CANCELLED]);
            SyncGoogleCalendar::dispatch($appointment, 'delete');
        });

        return $appointment->fresh();
    }

    // ----------------------------------------------------------
    // REPROGRAMAR TURNO (sin login, via token)
    // ----------------------------------------------------------
    public function reschedule(Appointment $appointment, array $data): Appointment
    {
        $minHours = (int) Setting::get('reschedule_min_hours_before', 24);

        if (!$appointment->isReschedulable($minHours)) {
            throw new \RuntimeException(
                "No se puede reprogramar con menos de {$minHours} horas de anticipación.", 422
            );
        }

        return DB::transaction(function () use ($appointment, $data) {
            $professional = $appointment->professional;
            $startsAt     = Carbon::parse($data['datetime'], self::TIMEZONE)->utc();
            $endsAt       = $startsAt->copy()->addMinutes($professional->appointment_duration_min);

            // Verificar disponibilidad del nuevo slot con lock
            $conflict = Appointment::where('user_id', $professional->id)
                ->where('id', '!=', $appointment->id) // excluir el turno actual
                ->whereIn('status', [Appointment::STATUS_CONFIRMED, Appointment::STATUS_RESCHEDULED])
                ->where(function ($q) use ($startsAt, $endsAt) {
                    $q->where('starts_at', '<', $endsAt)
                      ->where('ends_at', '>', $startsAt);
                })
                ->lockForUpdate()
                ->first();

            if ($conflict) {
                throw new \RuntimeException('El nuevo horario no está disponible.', 409);
            }

            // Marcar el turno original como reprogramado
            $appointment->update(['status' => Appointment::STATUS_RESCHEDULED]);

            // Crear el nuevo turno manteniendo el histórico y la tarifa original
            $newAppointment = Appointment::create([
                'patient_id'              => $appointment->patient_id,
                'user_id'                 => $appointment->user_id,
                'starts_at'               => $startsAt,
                'ends_at'                 => $endsAt,
                'status'                  => Appointment::STATUS_CONFIRMED,
                'fee_at_booking'          => $appointment->fee_at_booking, // mantiene tarifa original
                'cancel_token'            => $appointment->cancel_token,
                'cancel_token_expires_at' => $appointment->cancel_token_expires_at,
                'rescheduled_from_id'     => $appointment->id,
            ]);

            SyncGoogleCalendar::dispatch($appointment, 'delete');
            SyncGoogleCalendar::dispatch($newAppointment, 'create');
            SendAppointmentConfirmation::dispatch($newAppointment, $appointment->cancel_token, 'rescheduled');

            return $newAppointment->load(['patient', 'professional']);
        });
    }

    // ----------------------------------------------------------
    // MARCAR COMO ATENDIDO — dispara registro de ingresos
    // ----------------------------------------------------------
    public function markAsAttended(Appointment $appointment): Appointment
    {
        // ... (código existente)
    }

    /**
     * BLOQUEAR SLOT MANUALMENTE
     */
    public function block(string $professionalId, string $datetime, ?string $notes = null): Appointment
    {
        return DB::transaction(function () use ($professionalId, $datetime, $notes) {
            $professional = User::findOrFail($professionalId);
            $startsAt     = Carbon::parse($datetime, self::TIMEZONE)->utc();
            $endsAt       = $startsAt->copy()->addMinutes($professional->appointment_duration_min);

            // Verificar si ya hay algo en ese horario (incluyendo otros bloques)
            $conflict = Appointment::where('user_id', $professional->id)
                ->whereIn('status', [
                    Appointment::STATUS_CONFIRMED, 
                    Appointment::STATUS_RESCHEDULED,
                    Appointment::STATUS_BLOCKED
                ])
                ->where(function ($q) use ($startsAt, $endsAt) {
                    $q->where('starts_at', '<', $endsAt)
                      ->where('ends_at', '>', $startsAt);
                })
                ->lockForUpdate()
                ->first();

            if ($conflict) {
                throw new \RuntimeException('Ya hay un turno o bloqueo en este horario.', 409);
            }

            return Appointment::create([
                'patient_id'     => '00000000-0000-0000-0000-000000000000', // ID ficticio o nullable si prefieres
                'user_id'        => $professional->id,
                'starts_at'      => $startsAt,
                'ends_at'        => $endsAt,
                'status'         => Appointment::STATUS_BLOCKED,
                'fee_at_booking' => 0,
                'notes'          => $notes ?: 'Bloqueo manual por compromiso profesional',
            ]);
        });
    }

    // ----------------------------------------------------------
    // PRIVADOS
    // ----------------------------------------------------------

    private function findOrCreatePatient(array $data): Patient
    {
        // Buscar por DNI (identificador principal)
        return Patient::updateOrCreate(
            ['dni' => $data['dni']],
            [
                'first_name' => $data['first_name'],
                'last_name'  => $data['last_name'],
                'phone'      => $data['phone'],
                'email'      => $data['email'] ?? null,
            ]
        );
    }
}
