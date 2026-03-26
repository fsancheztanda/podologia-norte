<?php
// ============================================================
// RUTAS — routes/api.php
// ============================================================

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\Admin\AppointmentAdminController;
use App\Http\Controllers\Api\Admin\HolidayController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\PatientController;
use Illuminate\Support\Facades\Route;

// ----------------------------------------------------------
// RUTAS PÚBLICAS (sin autenticación)
// ----------------------------------------------------------

// Profesionales disponibles (para selector en formulario público)
Route::get('/professionals', [AvailabilityController::class, 'professionals']);

// Disponibilidad de slots
Route::get('/availability', [AvailabilityController::class, 'index']);

// Reservar turno
Route::post('/appointments', [AppointmentController::class, 'store']);

// Cancelación/reprogramación por token (sin login)
Route::prefix('appointments')->group(function () {
    Route::get('/cancel/{token}', [AppointmentController::class, 'showCancelPage']);
    Route::post('/cancel/{token}', [AppointmentController::class, 'cancel']);
    Route::post('/reschedule/{token}', [AppointmentController::class, 'reschedule']);
});

// Auth
Route::post('/admin/login', [AuthController::class, 'login']);

// ----------------------------------------------------------
// RUTAS PRIVADAS (requieren Sanctum token)
// ----------------------------------------------------------
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    // Agenda
    Route::get('/appointments', [AppointmentAdminController::class, 'index']);
    Route::patch('/appointments/{appointment}/attend', [AppointmentAdminController::class, 'markAttended']);

    // Pacientes (solo admin ve datos de contacto — validado en controller/policy)
    Route::get('/patients', [PatientController::class, 'index']);
    Route::get('/patients/{patient}', [PatientController::class, 'show']);
    Route::post('/patients/{patient}/medical-records', [PatientController::class, 'addMedicalRecord']);

    // Reportes financieros (solo admin)
    Route::middleware(['can:viewFinancials,App\Models\User'])->group(function () {
        Route::get('/reports/income', [ReportController::class, 'income']);
        Route::get('/reports/income/export', [ReportController::class, 'exportCsv']);
    });

    // Feriados y días bloqueados (solo admin)
    Route::middleware(['can:manageHolidays,App\Models\User'])->group(function () {
        Route::get('/holidays', [HolidayController::class, 'index']);
        Route::post('/holidays/{date}/toggle', [HolidayController::class, 'toggle']);
        Route::post('/holidays/sync', [HolidayController::class, 'sync']);
        Route::post('/blocked-days', [HolidayController::class, 'blockDay']);
        Route::delete('/blocked-days/{date}', [HolidayController::class, 'unblockDay']);
    });

    // Configuración (solo admin)
    Route::get('/settings', fn () => response()->json(\App\Models\Setting::all()));
    Route::patch('/settings/{key}', function ($key, \Illuminate\Http\Request $request) {
        abort_if(!auth()->user()->isAdmin(), 403);
        \App\Models\Setting::set($key, $request->value);
        return response()->json(['key' => $key, 'value' => $request->value]);
    });
});


// ============================================================
// FORM REQUESTS — app/Http/Requests/BookAppointmentRequest.php
// ============================================================

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ruta pública
    }

    public function rules(): array
    {
        return [
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'phone'           => 'required|string|min:8|max:20',
            'email'           => 'nullable|email|max:150',
            'professional_id' => 'required|uuid|exists:users,id',
            'datetime'        => 'required|date|after:now',
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required'      => 'El nombre es obligatorio.',
            'last_name.required'       => 'El apellido es obligatorio.',
            'phone.required'           => 'El teléfono es obligatorio.',
            'professional_id.required' => 'Elegí una profesional.',
            'professional_id.exists'   => 'La profesional seleccionada no existe.',
            'datetime.required'        => 'Seleccioná un horario.',
            'datetime.after'           => 'El horario debe ser en el futuro.',
        ];
    }
}


// ============================================================
// POLICIES — app/Policies/AppointmentPolicy.php
// ============================================================

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    // Solo admin puede ver datos sensibles / financieros
    public function viewFinancials(User $user): bool
    {
        return $user->isAdmin();
    }

    // Solo admin gestiona feriados
    public function manageHolidays(User $user): bool
    {
        return $user->isAdmin();
    }

    // Admin puede todo; podóloga solo puede actualizar sus propios turnos
    public function update(User $user, Appointment $appointment): bool
    {
        return $user->isAdmin() || $user->id === $appointment->user_id;
    }
}
