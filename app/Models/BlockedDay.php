<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class BlockedDay extends Model
{
    use HasUuids;

    protected $fillable = ['date', 'reason', 'created_by'];

    protected $casts = [
        'date' => 'date',
    ];
}
