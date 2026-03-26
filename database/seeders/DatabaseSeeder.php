<?php
// database/seeders/DatabaseSeeder.php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ----------------------------------------------------------
        // Profesionales
        // ----------------------------------------------------------
        User::create([
            'id'                       => Str::uuid(),
            'name'                     => 'Nora Tanda',
            'email'                    => 'nora@consultorio.com',
            'password'                 => Hash::make('nora123'),
            'role'                     => 'admin',
            'appointment_duration_min' => 60,
            'commission_pct'           => 100.00,
            'working_hours'            => [
                'mon' => ['09:00', '18:00'],
                'tue' => ['09:00', '18:00'],
                'wed' => ['09:00', '18:00'],
                'thu' => ['09:00', '18:00'],
                'fri' => ['09:00', '18:00'],
                'sat' => null,
                'sun' => null,
            ],
        ]);

        User::create([
            'id'                       => Str::uuid(),
            'name'                     => 'Box 2',
            'email'                    => 'box2@consultorio.com',
            'password'                 => Hash::make('box123'),
            'role'                     => 'podologa',
            'appointment_duration_min' => 45,
            'commission_pct'           => 50.00,
            'working_hours'            => [
                'mon' => ['09:00', '18:00'],
                'tue' => ['09:00', '18:00'],
                'wed' => ['09:00', '18:00'],
                'thu' => ['09:00', '18:00'],
                'fri' => ['09:00', '18:00'],
                'sat' => null,
                'sun' => null,
            ],
        ]);

        // ----------------------------------------------------------
        // Configuración del sistema
        // ----------------------------------------------------------
        $settings = [
            [
                'key'         => 'appointment_fee',
                'value'       => '40000',
                'type'        => 'decimal',
                'description' => 'Tarifa por turno en ARS',
            ],
            [
                'key'         => 'consultation_name',
                'value'       => 'Podología Olivos',
                'type'        => 'string',
                'description' => 'Nombre del consultorio',
            ],
            [
                'key'         => 'consultation_phone',
                'value'       => '+54911XXXXXXXX',
                'type'        => 'string',
                'description' => 'Teléfono de contacto',
            ],
            [
                'key'         => 'reschedule_min_hours_before',
                'value'       => '24',
                'type'        => 'integer',
                'description' => 'Horas mínimas de anticipación para reprogramar',
            ],
            [
                'key'         => 'cancel_token_expiry_days',
                'value'       => '30',
                'type'        => 'integer',
                'description' => 'Días de validez del token de cancelación',
            ],
            [
                'key'         => 'reminder_hours_before',
                'value'       => '24',
                'type'        => 'integer',
                'description' => 'Horas antes del turno para enviar recordatorio WhatsApp',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::create($setting);
        }

        // ----------------------------------------------------------
        // Feriados 2026 (Manual Backup)
        // ----------------------------------------------------------
        $holidays = [
            ['date' => '2026-01-01', 'name' => 'Año Nuevo', 'year' => 2026],
            ['date' => '2026-03-24', 'name' => 'Día de la Memoria', 'year' => 2026],
            ['date' => '2026-04-02', 'name' => 'Día del Veterano y de los Caídos en la Guerra de Malvinas', 'year' => 2026],
            ['date' => '2026-04-03', 'name' => 'Viernes Santo', 'year' => 2026],
            ['date' => '2026-05-01', 'name' => 'Día del Trabajador', 'year' => 2026],
            ['date' => '2026-05-25', 'name' => 'Día de la Revolución de Mayo', 'year' => 2026],
            ['date' => '2026-06-20', 'name' => 'Paso a la Inmortalidad del Gral. Manuel Belgrano', 'year' => 2026],
            ['date' => '2026-07-09', 'name' => 'Día de la Independencia', 'year' => 2026],
            ['date' => '2026-12-08', 'name' => 'Día de la Inmaculada Concepción de María', 'year' => 2026],
            ['date' => '2026-12-25', 'name' => 'Navidad', 'year' => 2026],
        ];

        foreach ($holidays as $h) {
            \App\Models\Holiday::create($h + ['is_working_day' => false]);
        }
    }
}
