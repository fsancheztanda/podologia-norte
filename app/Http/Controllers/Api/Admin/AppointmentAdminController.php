<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\AppointmentService;
use Illuminate\Http\Request;

class AppointmentAdminController extends Controller
{
    public function __construct(private AppointmentService $service) {}

    /**
     * GET /api/admin/appointments
     * Filtros: date, professional_id, status
     */
    public function index(Request $request)
    {
        $query = Appointment::with(['patient', 'professional', 'incomeRecord'])
            ->orderBy('starts_at');

        $tz = 'America/Argentina/Buenos_Aires';

        if ($request->start_date && $request->end_date) {
            $start = \Carbon\Carbon::parse($request->start_date, $tz)->startOfDay()->utc();
            $end   = \Carbon\Carbon::parse($request->end_date, $tz)->endOfDay()->utc();
            $query->whereBetween('starts_at', [$start, $end]);
        } elseif ($request->date) {
            // Filtrar por fecha local Argentina
            $start = \Carbon\Carbon::parse($request->date, $tz)->startOfDay()->utc();
            $end   = \Carbon\Carbon::parse($request->date, $tz)->endOfDay()->utc();
            $query->whereBetween('starts_at', [$start, $end]);
        }

        if ($request->professional_id) {
            $query->where('user_id', $request->professional_id);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        // Si el usuario es podóloga (no admin), solo ve sus propios turnos
        if (!auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        // Ocultar datos personales del paciente si no es admin
        $appointments = $query->paginate(50)->through(function ($appt) {
            return $this->formatAppointment($appt);
        });

        return response()->json($appointments);
    }

    /**
     * PATCH /api/admin/appointments/{id}/attend
     */
    public function markAttended(Appointment $appointment)
    {
        // Nota: El middleware de autorización debe estar configurado en las rutas
        // o usar $this->authorize('update', $appointment);

        try {
            $result = $this->service->markAsAttended($appointment);
            return response()->json([
                'message'     => 'Turno marcado como atendido.',
                'appointment' => $this->formatAppointment($result),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/admin/appointments/block
     */
    public function blockSlot(Request $request)
    {
        $request->validate([
            'professional_id' => 'required|uuid|exists:users,id',
            'datetime'        => 'required|date|after:now',
            'notes'           => 'nullable|string|max:255',
        ]);

        if (!auth()->user()->isAdmin() && auth()->id() !== $request->professional_id) {
            abort(403);
        }

        try {
            $appointment = $this->service->block(
                $request->professional_id, 
                $request->datetime, 
                $request->notes
            );
            return response()->json(['message' => 'Horario bloqueado correctamente.', 'appointment' => $appointment], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function formatAppointment(Appointment $appt): array
    {
        $isAdmin = auth()->user()?->isAdmin();
        $tz      = 'America/Argentina/Buenos_Aires';

        $data = [
            'id'           => $appt->id,
            'starts_at'    => $appt->starts_at->setTimezone($tz)->toIso8601String(),
            'ends_at'      => $appt->ends_at->setTimezone($tz)->toIso8601String(),
            'status'       => $appt->status,
            'professional' => ['id' => $appt->user_id, 'name' => $appt->professional?->name],
            'patient'      => [
                'id'        => $appt->patient_id,
                'full_name' => $appt->patient?->full_name,
                // Datos sensibles: solo admin
                'phone'     => $isAdmin ? $appt->patient?->phone : null,
                'email'     => $isAdmin ? $appt->patient?->email : null,
            ],
            'fee'          => $appt->fee_at_booking,
            'income'       => $isAdmin ? $appt->incomeRecord : null,
        ];

        return $data;
    }
}
