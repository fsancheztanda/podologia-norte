<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'patient_id', 'user_id', 'starts_at', 'ends_at',
        'status', 'fee_at_booking', 'cancel_token',
        'cancel_token_expires_at', 'rescheduled_from_id', 'notes',
    ];

    protected $casts = [
        'starts_at'               => 'datetime',
        'ends_at'                 => 'datetime',
        'cancel_token_expires_at' => 'datetime',
        'fee_at_booking'          => 'decimal:2',
    ];

    // Constantes de estado
    const STATUS_CONFIRMED    = 'confirmed';
    const STATUS_CANCELLED    = 'cancelled';
    const STATUS_RESCHEDULED  = 'rescheduled';
    const STATUS_ATTENDED     = 'attended';
    const STATUS_BLOCKED      = 'blocked';

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function professional()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function incomeRecord()
    {
        return $this->hasOne(IncomeRecord::class);
    }

    public function rescheduledFrom()
    {
        return $this->belongsTo(Appointment::class, 'rescheduled_from_id');
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_CONFIRMED, self::STATUS_RESCHEDULED])
            && $this->starts_at->isFuture();
    }

    public function isReschedulable(int $minHoursBefore = 24): bool
    {
        return $this->isCancellable()
            && $this->starts_at->diffInHours(now()) >= $minHoursBefore;
    }

    public function hasValidCancelToken(string $token): bool
    {
        return $this->cancel_token === $token
            && $this->cancel_token_expires_at?->isFuture();
    }
}
