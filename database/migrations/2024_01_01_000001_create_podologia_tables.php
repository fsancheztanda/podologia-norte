<?php
// ============================================================
// MIGRACIONES — ejecutar en orden con: php artisan migrate
// Archivo: database/migrations/2024_01_01_000001_create_podologia_tables.php
// ============================================================

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------
        // USUARIOS (admin + podólogas)
        // ----------------------------------------------------------
        // Schema::create('users', function (Blueprint $table) {
        //     $table->uuid('id')->primary();
        //     $table->string('name');
        //     $table->string('email')->unique();
        //     $table->string('password');
        //     $table->enum('role', ['admin', 'podologa']);
        //     $table->boolean('is_active')->default(true);

        //     // Configuración por profesional
        //     $table->integer('appointment_duration_min')->default(60); // dueña=60, empleada=45
        //     $table->decimal('commission_pct', 5, 2)->default(50.00); // % que se lleva la profesional

        //     // Horario (JSON: {"mon":["09:00","18:00"], "tue":...})
        //     $table->json('working_hours')->nullable();

        //     $table->rememberToken();
        //     $table->timestamps();
        // });

        // ----------------------------------------------------------
        // PACIENTES
        // ----------------------------------------------------------
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('dni')->unique()->index();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->date('dob')->nullable();
            $table->integer('visit_count')->default(0);
            $table->timestamp('last_visit_at')->nullable();
            $table->timestamps();

            $table->index(['last_name', 'first_name']);
            $table->index('phone');
        });

        // ----------------------------------------------------------
        // TURNOS
        // ----------------------------------------------------------
        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->uuid('user_id'); // profesional asignada
            $table->dateTime('starts_at'); // timezone UTC internamente
            $table->dateTime('ends_at');
            $table->enum('status', [
                'confirmed',
                'cancelled',
                'rescheduled',
                'attended',
            ])->default('confirmed');

            // Tarifa histórica: se guarda al momento de reservar, nunca se recalcula
            $table->decimal('fee_at_booking', 10, 2);

            // Token seguro para cancelación/reprogramación sin login
            $table->string('cancel_token', 64)->unique()->nullable();
            $table->timestamp('cancel_token_expires_at')->nullable();

            // Si fue reprogramado, referencia al turno original
            $table->uuid('rescheduled_from_id')->nullable();

            // Notas internas del admin
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('patient_id')->references('id')->on('patients');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('rescheduled_from_id')->references('id')->on('appointments');

            // Índices clave para queries de disponibilidad
            $table->index(['user_id', 'starts_at', 'status']);
            $table->index(['starts_at', 'ends_at']);
            $table->index('cancel_token');
        });

        // ----------------------------------------------------------
        // REGISTRO DE INGRESOS
        // Se crea cuando un turno pasa a "attended"
        // ----------------------------------------------------------
        Schema::create('income_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('appointment_id')->unique(); // 1-a-1 con turno
            $table->uuid('user_id'); // profesional que atendió

            $table->decimal('total_amount', 10, 2);    // = fee_at_booking
            $table->decimal('owner_share', 10, 2);     // para dueña
            $table->decimal('employee_share', 10, 2);  // para profesional (0 si es dueña)

            $table->date('recorded_date'); // fecha local Argentina
            $table->timestamps();

            $table->foreign('appointment_id')->references('id')->on('appointments');
            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['recorded_date', 'user_id']);
        });

        // ----------------------------------------------------------
        // FICHAS MÉDICAS
        // ----------------------------------------------------------
        Schema::create('medical_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->uuid('user_id'); // profesional que realizó la visita
            $table->uuid('appointment_id')->nullable();

            $table->date('visit_date');
            $table->string('treatment');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('patients');
            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['patient_id', 'visit_date']);
        });

        // ----------------------------------------------------------
        // FERIADOS (cacheados desde nolaborables.com.ar)
        // ----------------------------------------------------------
        Schema::create('holidays', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->string('name');
            $table->string('type')->nullable(); // inamovible, trasladable, puente
            $table->integer('year');

            // Admin puede marcar un feriado como laborable (excepción)
            $table->boolean('is_working_day')->default(false);

            $table->timestamps();
            $table->index('year');
        });

        // ----------------------------------------------------------
        // DÍAS BLOQUEADOS MANUALMENTE (vacaciones, etc.)
        // ----------------------------------------------------------
        Schema::create('blocked_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('date')->unique();
            $table->string('reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });

        // ----------------------------------------------------------
        // CONFIGURACIÓN DEL SISTEMA (key-value)
        // ----------------------------------------------------------
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string, integer, decimal, json, boolean
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // Token sessions para Sanctum
        // Schema::create('personal_access_tokens', function (Blueprint $table) {
        //     $table->id();
        //     $table->morphs('tokenable');
        //     $table->string('name');
        //     $table->string('token', 64)->unique();
        //     $table->text('abilities')->nullable();
        //     $table->timestamp('last_used_at')->nullable();
        //     $table->timestamp('expires_at')->nullable();
        //     $table->timestamps();
        // });

        // Queue jobs para workers
        // Schema::create('jobs', function (Blueprint $table) {
        //     $table->id();
        //     $table->string('queue')->index();
        //     $table->longText('payload');
        //     $table->unsignedTinyInteger('attempts');
        //     $table->unsignedInteger('reserved_at')->nullable();
        //     $table->unsignedInteger('available_at');
        //     $table->unsignedInteger('created_at');
        // });

        // Schema::create('failed_jobs', function (Blueprint $table) {
        //     $table->id();
        //     $table->string('uuid')->unique();
        //     $table->text('connection');
        //     $table->text('queue');
        //     $table->longText('payload');
        //     $table->longText('exception');
        //     $table->timestamp('failed_at')->useCurrent();
        // });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('blocked_days');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('medical_records');
        Schema::dropIfExists('income_records');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('patients');
        Schema::dropIfExists('users');
    }
};
