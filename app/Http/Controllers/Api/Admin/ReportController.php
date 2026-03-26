<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\IncomeRecord;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * GET /api/admin/reports/income?from=2024-01-01&to=2024-01-31
     */
    public function income(Request $request)
    {
        // $this->authorize('viewFinancials', \App\Models\User::class);

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

    /**
     * GET /api/admin/reports/income/export?from=...&to=... — CSV
     */
    public function exportCsv(Request $request)
    {
        // $this->authorize('viewFinancials', \App\Models\User::class);

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
