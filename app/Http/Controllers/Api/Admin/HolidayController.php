<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockedDay;
use App\Models\Holiday;
use App\Services\HolidayService;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    public function __construct(private HolidayService $service) {}

    /**
     * GET /api/admin/holidays?year=2024&month=3
     */
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

    /**
     * POST /api/admin/holidays/{date}/toggle
     */
    public function toggle(string $date)
    {
        $holiday = $this->service->toggleWorkingDay($date);
        return response()->json(['holiday' => $holiday]);
    }

    /**
     * POST /api/admin/holidays/sync — forzar re-fetch desde API
     */
    public function sync(Request $request)
    {
        $year  = $request->integer('year', now()->year);
        $count = $this->service->syncYear($year);
        return response()->json(['message' => "Sincronizados {$count} feriados para {$year}."]);
    }

    /**
     * POST /api/admin/blocked-days
     */
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

    /**
     * DELETE /api/admin/blocked-days/{date}
     */
    public function unblockDay(string $date)
    {
        BlockedDay::where('date', $date)->delete();
        return response()->json(['message' => 'Día desbloqueado.']);
    }
}
