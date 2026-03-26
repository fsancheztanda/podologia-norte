<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class IncomeRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'appointment_id', 'user_id', 'total_amount',
        'owner_share', 'employee_share', 'recorded_date',
    ];

    protected $casts = [
        'total_amount'    => 'decimal:2',
        'owner_share'     => 'decimal:2',
        'employee_share'  => 'decimal:2',
        'recorded_date'   => 'date',
    ];

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function professional()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
