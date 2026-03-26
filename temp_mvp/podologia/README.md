# Sistema de Turnos Podológico — MVP Laravel 11

Stack: Laravel 11 · MySQL · Railway · Sanctum · Queues

## Instalación local

```bash
composer create-project laravel/laravel podologia
cd podologia

# Copiar todos los archivos de este MVP sobre el proyecto base
# Luego:

composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"

# Configurar .env (ver .env.example abajo)
php artisan migrate --seed
php artisan queue:work --daemon &
php artisan serve
```

## Variables de entorno (.env)

```env
APP_NAME="Podología Olivos"
APP_ENV=production
APP_KEY=         # php artisan key:generate
APP_URL=https://tu-app.railway.app

DB_CONNECTION=mysql
DB_HOST=tu-host-railway
DB_PORT=3306
DB_DATABASE=podologia
DB_USERNAME=root
DB_PASSWORD=tu-password

QUEUE_CONNECTION=database
SESSION_DRIVER=database

# Timezone (crítico)
APP_TIMEZONE=America/Argentina/Buenos_Aires

# Email via Brevo SMTP
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=tu-email@dominio.com
MAIL_PASSWORD=tu-api-key-brevo
MAIL_FROM_ADDRESS=turnos@tuconsultorio.com
MAIL_FROM_NAME="Podología Olivos"

# WhatsApp Meta Cloud API
WHATSAPP_TOKEN=tu-token
WHATSAPP_PHONE_ID=tu-phone-id
WHATSAPP_API_URL=https://graph.facebook.com/v18.0

# Google Calendar
GOOGLE_CALENDAR_ID=tu-calendar-id
GOOGLE_CREDENTIALS_PATH=storage/app/google-credentials.json

# Cancelación token HMAC
CANCEL_TOKEN_SECRET=una-clave-secreta-larga-aleatoria
```

## Endpoints principales

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | /api/professionals | Lista de profesionales activas |
| GET | /api/availability | Slots disponibles por profesional y fecha |
| POST | /api/appointments | Crear turno (público) |
| GET | /api/appointments/cancel/{token} | Vista cancelación (sin login) |
| POST | /api/appointments/cancel/{token} | Cancelar turno |
| POST | /api/appointments/reschedule/{token} | Reprogramar turno |
| POST | /api/admin/login | Login admin/podóloga |
| GET | /api/admin/appointments | Agenda (con filtros) |
| PATCH | /api/admin/appointments/{id}/attend | Marcar como atendido |
| GET | /api/admin/reports/income | Reporte financiero |
| GET | /api/admin/patients | Lista pacientes |
| GET | /api/admin/patients/{id} | Ficha paciente |
| GET | /api/admin/holidays | Feriados y días bloqueados |
| POST | /api/admin/holidays/{date}/toggle | Toggle laborable/no-laborable |
| POST | /api/admin/blocked-days | Bloquear día manual |

## Lógica de concurrencia

Los slots se reservan con `DB::transaction()` + `lockForUpdate()` sobre
la tabla `appointments`. Si dos usuarios intentan el mismo slot
simultáneamente, uno obtiene el lock y el otro recibe 409 Conflict.

## Deploy en Railway

1. Crear proyecto en railway.app
2. Agregar servicio MySQL
3. Conectar repo GitHub (o usar Railway CLI)
4. Configurar variables de entorno
5. Railway detecta PHP automáticamente via `composer.json`
6. Agregar en `Procfile`:
   ```
   web: php artisan serve --host=0.0.0.0 --port=$PORT
   worker: php artisan queue:work --sleep=3 --tries=3
   ```
