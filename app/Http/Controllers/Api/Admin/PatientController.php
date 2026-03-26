<?php
// app/Http/Controllers/Api/Admin/PatientController.php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MedicalRecord;
use App\Models\Patient;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    // GET /api/admin/patients?search=garcía
    public function index(Request $request)
    {
        $isAdmin = auth()->user()->isAdmin();

        $query = Patient::withCount('appointments')
            ->orderBy('last_name');

        if ($request->search) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', $search)
                  ->orWhere('last_name', 'like', $search)
                  ->orWhere('phone', 'like', $search);
            });
        }

        return $query->paginate(30)->through(function ($patient) use ($isAdmin) {
            return [
                'id'            => $patient->id,
                'full_name'     => $patient->full_name,
                'phone'         => $isAdmin ? $patient->phone : null,
                'email'         => $isAdmin ? $patient->email : null,
                'visit_count'   => $patient->visit_count,
                'last_visit_at' => $patient->last_visit_at?->toDateString(),
            ];
        });
    }

    // GET /api/admin/patients/{id}
    public function show(Patient $patient)
    {
        $isAdmin = auth()->user()->isAdmin();

        $medicalRecords = $patient->medicalRecords()
            ->with('professional:id,name')
            ->get();

        return response()->json([
            'patient' => [
                'id'            => $patient->id,
                'full_name'     => $patient->full_name,
                'phone'         => $isAdmin ? $patient->phone : null,
                'email'         => $isAdmin ? $patient->email : null,
                'dob'           => $isAdmin ? $patient->dob?->toDateString() : null,
                'visit_count'   => $patient->visit_count,
                'last_visit_at' => $patient->last_visit_at?->toDateString(),
            ],
            'medical_records' => $medicalRecords,
        ]);
    }

    // POST /api/admin/patients/{id}/medical-records
    public function addMedicalRecord(Request $request, Patient $patient)
    {
        $request->validate([
            'visit_date'     => 'required|date',
            'treatment'      => 'required|string|max:500',
            'notes'          => 'nullable|string',
            'appointment_id' => 'nullable|uuid|exists:appointments,id',
        ]);

        $record = MedicalRecord::create([
            'patient_id'     => $patient->id,
            'user_id'        => auth()->id(),
            'appointment_id' => $request->appointment_id,
            'visit_date'     => $request->visit_date,
            'treatment'      => $request->treatment,
            'notes'          => $request->notes,
        ]);

        return response()->json(['record' => $record], 201);
    }
}
