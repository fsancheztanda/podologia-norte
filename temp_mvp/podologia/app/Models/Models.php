<?php
// ============================================================
// MODELOS ELOQUENT
// Guardar cada clase en su propio archivo en app/Models/
// ============================================================

// ---- app/Models/User.php ----
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids;

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

    // Verifica si trabaja en un día de la semana (mon, tue, etc.)
    public function worksOn(string $dayOfWeek): bool
    {
        return !empty($this->working_hours[$dayOfWeek]);
    }

    // Retorna [inicio, fin] del horario para un día dado
    public function hoursFor(string $dayOfWeek): ?array
    {
        return $this->working_hours[$dayOfWeek] ?? null;
    }
}

// ============================================================

// ---- app/Models/Patient.php ----
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Patient extends Model
{
    use HasUuids;

    protected $fillable = [
        'first_name', 'last_name', 'phone', 'email', 'dob',
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

// ============================================================

// ---- app/Models/Appointment.php ----
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

// ============================================================

// ---- app/Models/IncomeRecord.php ----
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

// ============================================================

// ---- app/Models/MedicalRecord.php ----
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

// ============================================================

// ---- app/Models/Setting.php ----
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'type', 'description'];

    // Helper estático para leer settings con cache de 10 minutos
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("setting_{$key}", 600, function () use ($key, $default) {
            $setting = static::find($key);
            return $setting ? $setting->typedValue() : $default;
        });
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
        Cache::forget("setting_{$key}");
    }

    public function typedValue(): mixed
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'decimal' => (float) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json'    => json_decode($this->value, true),
            default   => $this->value,
        };
    }
}

// ============================================================

// ---- app/Models/Holiday.php ----
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

// ============================================================

// ---- app/Models/BlockedDay.php ----
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
