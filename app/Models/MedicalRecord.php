<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MedicalRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'patient_id', 'user_id', 'appointment_id',
        'visit_date', 'treatment', 'notes',
    ];

    protected $casts = [
        'visit_date' => 'date',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function professional()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
