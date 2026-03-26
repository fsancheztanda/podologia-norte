<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookAppointmentRequest;
use App\Models\Appointment;
use App\Services\AppointmentService;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function __construct(private AppointmentService $service) {}

    /**
     * POST /api/appointments — público, sin login
     */
    public function store(BookAppointmentRequest $request)
    {
        try {
            $appointment = $this->service->book($request->validated());

            return response()->json([
                'message'        => 'Turno confirmado. Revisá tu email.',
                'appointment_id' => $appointment->id,
                'starts_at'      => $appointment->starts_at->setTimezone('America/Argentina/Buenos_Aires')->toIso8601String(),
                'professional'   => $appointment->professional->name,
            ], 201);

        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 422);
        }
    }

    /**
     * GET /api/appointments/cancel/{token}
     */
    public function showCancelPage(string $token)
    {
        $appointment = Appointment::where('cancel_token', $token)->firstOrFail();

        if (!$appointment->hasValidCancelToken($token)) {
            return response()->json(['message' => 'El link expiró o no es válido.'], 410);
        }

        return response()->json([
            'appointment' => [
                'id'           => $appointment->id,
                'starts_at'    => $appointment->starts_at->setTimezone('America/Argentina/Buenos_Aires')->toIso8601String(),
                'professional' => $appointment->professional->name,
                'status'       => $appointment->status,
                'cancellable'  => $appointment->isCancellable(),
            ],
        ]);
    }

    /**
     * POST /api/appointments/cancel/{token}
     */
    public function cancel(string $token)
    {
        $appointment = Appointment::where('cancel_token', $token)->firstOrFail();

        if (!$appointment->hasValidCancelToken($token)) {
            return response()->json(['message' => 'El link expiró o no es válido.'], 410);
        }

        try {
            $this->service->cancel($appointment);
            return response()->json(['message' => 'Turno cancelado correctamente.']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/appointments/reschedule/{token}
     */
    public function reschedule(Request $request, string $token)
    {
        $request->validate([
            'datetime' => 'required|date|after:now',
        ]);

        $appointment = Appointment::where('cancel_token', $token)->firstOrFail();

        if (!$appointment->hasValidCancelToken($token)) {
            return response()->json(['message' => 'El link expiró o no es válido.'], 410);
        }

        try {
            $new = $this->service->reschedule($appointment, $request->only('datetime'));

            return response()->json([
                'message'   => 'Turno reprogramado correctamente.',
                'starts_at' => $new->starts_at->setTimezone('America/Argentina/Buenos_Aires')->toIso8601String(),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 422);
        }
    }
}
