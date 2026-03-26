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
            'name'                     => 'Laura García',
            'email'                    => 'laura@consultorio.com',
            'password'                 => Hash::make('cambiar-esto-en-prod'),
            'role'                     => 'admin',
            'appointment_duration_min' => 60,
            'commission_pct'           => 100.00, // dueña: 100% para ella
            'working_hours'            => json_encode([
                'mon' => ['09:00', '18:00'],
                'tue' => ['09:00', '18:00'],
                'wed' => ['09:00', '18:00'],
                'thu' => ['09:00', '18:00'],
                'fri' => ['09:00', '18:00'],
                'sat' => null,
                'sun' => null,
            ]),
        ]);

        User::create([
            'id'                       => Str::uuid(),
            'name'                     => 'María Rodríguez',
            'email'                    => 'maria@consultorio.com',
            'password'                 => Hash::make('cambiar-esto-en-prod'),
            'role'                     => 'podologa',
            'appointment_duration_min' => 45,
            'commission_pct'           => 50.00, // empleada: 50% para ella, 50% para dueña
            'working_hours'            => json_encode([
                'mon' => ['09:00', '18:00'],
                'tue' => ['09:00', '18:00'],
                'wed' => ['09:00', '18:00'],
                'thu' => ['09:00', '18:00'],
                'fri' => ['09:00', '18:00'],
                'sat' => null,
                'sun' => null,
            ]),
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
    }
}
