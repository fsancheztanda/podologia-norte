<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $primaryKey = 'date';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['date', 'name', 'type', 'year', 'is_working_day'];

    protected $casts = [
        'date'           => 'date',
        'is_working_day' => 'boolean',
    ];
}
