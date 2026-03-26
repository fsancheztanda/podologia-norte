<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function __construct(private AvailabilityService $service) {}

    /**
     * GET /api/availability?professional_id=xxx&date=2024-03-15
     */
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

    /**
     * GET /api/professionals — lista de profesionales para el selector público
     */
    public function professionals()
    {
        $professionals = User::where('is_active', true)
            ->select('id', 'name', 'appointment_duration_min')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $professionals]);
    }
}
