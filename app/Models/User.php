<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'is_active',
        'appointment_duration_min', 'commission_pct', 'working_hours',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'working_hours'  => 'array',
        'is_active'      => 'boolean',
        'commission_pct' => 'decimal:2',
    ];

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Verifica si trabaja en un día de la semana (mon, tue, etc.)
     */
    public function worksOn(string $dayOfWeek): bool
    {
        return !empty($this->working_hours[$dayOfWeek]);
    }

    /**
     * Retorna [inicio, fin] del horario para un día dado
     */
    public function hoursFor(string $dayOfWeek): ?array
    {
        return $this->working_hours[$dayOfWeek] ?? null;
    }
}
