<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Patient extends Model
{
    use HasUuids;

    protected $fillable = [
        'dni', 'first_name', 'last_name', 'phone', 'email', 'dob',
        'visit_count', 'last_visit_at',
    ];

    protected $casts = [
        'dob'           => 'date',
        'last_visit_at' => 'datetime',
    ];

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function medicalRecords()
    {
        return $this->hasMany(MedicalRecord::class)->orderByDesc('visit_date');
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
