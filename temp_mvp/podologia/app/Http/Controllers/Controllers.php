<?php
// ============================================================
// CONTROLLERS — guardar cada uno en app/Http/Controllers/Api/
// ============================================================


// ---- app/Http/Controllers/Api/AvailabilityController.php ----
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function __construct(private AvailabilityService $service) {}

    // GET /api/availability?professional_id=xxx&date=2024-03-15
    public function index(Request $request)
    {
        $request->validate([
            'professional_id' => 'required|uuid|exists:users,id',
            'date'            => 'required|date_format:Y-m-d|after_or_equal:today',
        ]);

        $professional = User::findOrFail($request->professional_id);
        $slots        = $this->service->getSlotsForDate($professional, $request->date);

        return response()->json([
            'professional' => [
                'id'           => $professional->id,
                'name'         => $professional->name,
                'duration_min' => $professional->appointment_duration_min,
            ],
            'date'  => $request->date,
            'slots' => $slots,
        ]);
    }

    // GET /api/professionals — lista de profesionales para el selector público
    public function professionals()
    {
        $professionals = User::where('is_active', true)
            ->select('id', 'name', 'appointment_duration_min')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $professionals]);
    }
}


// ---- app/Http/Controllers/Api/AppointmentController.php ----
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookAppointmentRequest;
use App\Models\Appointment;
use App\Services\AppointmentService;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function __construct(private AppointmentService $service) {}

    // POST /api/appointments — público, sin login
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

    // GET /api/appointments/cancel/{token}
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

    // POST /api/appointments/cancel/{token}
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

    // POST /api/appointments/reschedule/{token}
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


// ---- app/Http/Controllers/Api/Admin/AppointmentAdminController.php ----
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\AppointmentService;
use Illuminate\Http\Request;

class AppointmentAdminController extends Controller
{
    public function __construct(private AppointmentService $service) {}

    // GET /api/admin/appointments
    // Filtros: date, professional_id, status
    public function index(Request $request)
    {
        $query = Appointment::with(['patient', 'professional', 'incomeRecord'])
            ->orderBy('starts_at');

        if ($request->date) {
            // Filtrar por fecha local Argentina
            $tz    = 'America/Argentina/Buenos_Aires';
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

    // PATCH /api/admin/appointments/{id}/attend
    public function markAttended(Appointment $appointment)
    {
        $this->authorize('update', $appointment);

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


// ---- app/Http/Controllers/Api/Admin/ReportController.php ----
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\IncomeRecord;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    // GET /api/admin/reports/income?period=month&from=2024-01-01&to=2024-01-31
    public function income(Request $request)
    {
        $this->authorize('viewFinancials', \App\Models\User::class);

        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $from = $request->from;
        $to   = $request->to;

        // Totales generales
        $totals = IncomeRecord::whereBetween('recorded_date', [$from, $to])
            ->selectRaw('
                COUNT(*) as total_appointments,
                SUM(total_amount) as total_income,
                SUM(owner_share) as total_owner,
                SUM(employee_share) as total_employee
            ')
            ->first();

        // Por profesional
        $byProfessional = IncomeRecord::whereBetween('recorded_date', [$from, $to])
            ->with('professional:id,name,role')
            ->selectRaw('user_id, COUNT(*) as appointments, SUM(total_amount) as income, SUM(owner_share) as owner_share, SUM(employee_share) as employee_share')
            ->groupBy('user_id')
            ->get();

        // Por día (para gráfico)
        $byDay = IncomeRecord::whereBetween('recorded_date', [$from, $to])
            ->selectRaw('recorded_date, COUNT(*) as appointments, SUM(total_amount) as income')
            ->groupBy('recorded_date')
            ->orderBy('recorded_date')
            ->get();

        // Ocupación: turnos atendidos vs slots totales disponibles (aprox.)
        $attended  = Appointment::where('status', 'attended')
            ->whereBetween(DB::raw('DATE(CONVERT_TZ(starts_at, "+00:00", "-03:00"))'), [$from, $to])
            ->count();
        $cancelled = Appointment::where('status', 'cancelled')
            ->whereBetween(DB::raw('DATE(CONVERT_TZ(starts_at, "+00:00", "-03:00"))'), [$from, $to])
            ->count();
        $total_bookings = Appointment::whereBetween(DB::raw('DATE(CONVERT_TZ(starts_at, "+00:00", "-03:00"))'), [$from, $to])
            ->count();

        return response()->json([
            'period'          => ['from' => $from, 'to' => $to],
            'totals'          => $totals,
            'by_professional' => $byProfessional,
            'by_day'          => $byDay,
            'occupation'      => [
                'attended'       => $attended,
                'cancelled'      => $cancelled,
                'total_bookings' => $total_bookings,
                'rate_pct'       => $total_bookings > 0
                    ? round(($attended / $total_bookings) * 100, 1)
                    : 0,
            ],
        ]);
    }

    // GET /api/admin/reports/income/export?from=...&to=... — CSV
    public function exportCsv(Request $request)
    {
        $this->authorize('viewFinancials', \App\Models\User::class);

        $request->validate(['from' => 'required|date', 'to' => 'required|date']);

        $records = IncomeRecord::with(['appointment.patient', 'professional'])
            ->whereBetween('recorded_date', [$request->from, $request->to])
            ->orderBy('recorded_date')
            ->get();

        $csv = "Fecha,Paciente,Profesional,Tarifa,Ingreso Consultorio,Ingreso Profesional\n";

        foreach ($records as $r) {
            $csv .= implode(',', [
                $r->recorded_date->toDateString(),
                "\"{$r->appointment->patient->full_name}\"",
                "\"{$r->professional->name}\"",
                $r->total_amount,
                $r->owner_share,
                $r->employee_share,
            ]) . "\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=ingresos-{$request->from}-{$request->to}.csv",
        ]);
    }
}


// ---- app/Http/Controllers/Api/Admin/HolidayController.php ----
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockedDay;
use App\Models\Holiday;
use App\Services\HolidayService;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    public function __construct(private HolidayService $service) {}

    // GET /api/admin/holidays?year=2024&month=3
    public function index(Request $request)
    {
        $year  = $request->integer('year', now()->year);
        $month = $request->integer('month');

        $holidays    = $month
            ? $this->service->getForMonth($year, $month)
            : Holiday::where('year', $year)->get()->keyBy(fn ($h) => $h->date->toDateString());

        $blockedDays = BlockedDay::whereYear('date', $year)
            ->when($month, fn ($q) => $q->whereMonth('date', $month))
            ->get()
            ->keyBy(fn ($d) => $d->date->toDateString());

        return response()->json([
            'holidays'     => $holidays,
            'blocked_days' => $blockedDays,
        ]);
    }

    // POST /api/admin/holidays/{date}/toggle
    public function toggle(string $date)
    {
        $holiday = $this->service->toggleWorkingDay($date);
        return response()->json(['holiday' => $holiday]);
    }

    // POST /api/admin/holidays/sync — forzar re-fetch desde API
    public function sync(Request $request)
    {
        $year  = $request->integer('year', now()->year);
        $count = $this->service->syncYear($year);
        return response()->json(['message' => "Sincronizados {$count} feriados para {$year}."]);
    }

    // POST /api/admin/blocked-days
    public function blockDay(Request $request)
    {
        $request->validate([
            'date'   => 'required|date_format:Y-m-d',
            'reason' => 'nullable|string|max:255',
        ]);

        $blocked = BlockedDay::create([
            'date'       => $request->date,
            'reason'     => $request->reason,
            'created_by' => auth()->id(),
        ]);

        return response()->json(['blocked_day' => $blocked], 201);
    }

    // DELETE /api/admin/blocked-days/{date}
    public function unblockDay(string $date)
    {
        BlockedDay::where('date', $date)->delete();
        return response()->json(['message' => 'Día desbloqueado.']);
    }
}


// ---- app/Http/Controllers/Api/AuthController.php ----
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // POST /api/admin/login
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->where('is_active', true)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciales inválidas.'], 401);
        }

        // Revocar tokens anteriores y crear uno nuevo
        $user->tokens()->delete();
        $token = $user->createToken('admin-session', ['*'], now()->addDays(30));

        return response()->json([
            'token'     => $token->plainTextToken,
            'user'      => [
                'id'   => $user->id,
                'name' => $user->name,
                'role' => $user->role,
            ],
        ]);
    }

    // POST /api/admin/logout
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada.']);
    }
}
