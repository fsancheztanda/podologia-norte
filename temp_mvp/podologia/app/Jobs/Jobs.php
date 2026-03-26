<?php
// ============================================================
// JOBS — Notificaciones asíncronas
// Guardar cada clase en app/Jobs/
// Se procesan con: php artisan queue:work
// ============================================================


// ---- app/Jobs/SendAppointmentConfirmation.php ----
namespace App\Jobs;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendAppointmentConfirmation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private Appointment $appointment,
        private string $cancelToken,
        private string $type = 'confirmed' // confirmed | rescheduled
    ) {}

    public function handle(): void
    {
        $patient = $this->appointment->patient;

        if (!$patient->email) {
            return; // Sin email, skip silencioso
        }

        $tz       = 'America/Argentina/Buenos_Aires';
        $startsAt = $this->appointment->starts_at->setTimezone($tz);

        $cancelUrl    = config('app.url') . "/turnos/cancelar/{$this->cancelToken}";
        $rescheduleUrl = config('app.url') . "/turnos/reprogramar/{$this->cancelToken}";

        $subject = $this->type === 'rescheduled'
            ? 'Tu turno fue reprogramado — Podología Olivos'
            : 'Confirmación de turno — Podología Olivos';

        $body = $this->buildEmailHtml([
            'patient_name'   => $patient->first_name,
            'professional'   => $this->appointment->professional->name,
            'date'           => $startsAt->translatedFormat('l j \d\e F \d\e Y'),
            'time'           => $startsAt->format('H:i'),
            'cancel_url'     => $cancelUrl,
            'reschedule_url' => $rescheduleUrl,
            'type'           => $this->type,
        ]);

        // Enviar con SMTP configurado en .env (Brevo, Gmail, etc.)
        Mail::html($body, function ($message) use ($patient, $subject) {
            $message->to($patient->email, $patient->full_name)
                    ->subject($subject)
                    ->from(config('mail.from.address'), config('mail.from.name'));
        });
    }

    private function buildEmailHtml(array $data): string
    {
        $action  = $data['type'] === 'rescheduled' ? 'reprogramado' : 'confirmado';
        $greeting = $data['type'] === 'rescheduled' ? 'Tu turno fue reprogramado' : 'Tu turno está confirmado';

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: Arial, sans-serif; max-width: 560px; margin: 0 auto; padding: 32px 16px; color: #333;">
  <h2 style="color: #1a6b5a;">{$greeting}</h2>
  <p>Hola {$data['patient_name']},</p>
  <p>Tu turno está <strong>{$action}</strong> para:</p>
  <div style="background: #f5f5f5; border-radius: 8px; padding: 16px; margin: 16px 0;">
    <p style="margin: 4px 0;"><strong>Profesional:</strong> {$data['professional']}</p>
    <p style="margin: 4px 0;"><strong>Fecha:</strong> {$data['date']}</p>
    <p style="margin: 4px 0;"><strong>Hora:</strong> {$data['time']} hs</p>
  </div>
  <p>Si necesitás cancelar o reprogramar:</p>
  <p>
    <a href="{$data['cancel_url']}" style="color: #c0392b;">Cancelar turno</a> &nbsp;|&nbsp;
    <a href="{$data['reschedule_url']}" style="color: #1a6b5a;">Reprogramar turno</a>
  </p>
  <p style="color: #888; font-size: 12px; margin-top: 32px;">
    Podología Olivos · Olivos, Buenos Aires<br>
    Este link es personal. No lo compartas.
  </p>
</body>
</html>
HTML;
    }
}


// ---- app/Jobs/SendWhatsAppReminder.php ----
namespace App\Jobs;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWhatsAppReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(private Appointment $appointment) {}

    public function handle(): void
    {
        // Verificar que el turno siga confirmado antes de enviar
        $this->appointment->refresh();

        if (!in_array($this->appointment->status, [
            Appointment::STATUS_CONFIRMED,
            Appointment::STATUS_RESCHEDULED,
        ])) {
            return; // Turno cancelado, no enviar
        }

        $patient = $this->appointment->patient;
        $phone   = $this->normalizePhone($patient->phone);

        if (!$phone) {
            Log::warning("WhatsApp: número inválido para paciente {$patient->id}");
            return;
        }

        $tz       = 'America/Argentina/Buenos_Aires';
        $startsAt = $this->appointment->starts_at->setTimezone($tz);

        // Meta Cloud API — template de mensaje (previamente aprobado)
        // Para usar texto libre se necesita estar dentro de la ventana de 24hs de conversación.
        // En producción usar un template aprobado por Meta.
        $response = Http::withToken(config('services.whatsapp.token'))
            ->post(config('services.whatsapp.api_url') . '/' . config('services.whatsapp.phone_id') . '/messages', [
                'messaging_product' => 'whatsapp',
                'to'                => $phone,
                'type'              => 'text',
                'text'              => [
                    'body' => "Hola {$patient->first_name}! Te recordamos que mañana tenés turno en Podología Olivos a las {$startsAt->format('H:i')} hs con {$this->appointment->professional->name}. ¡Nos vemos!"
                ],
            ]);

        if (!$response->successful()) {
            Log::error("WhatsApp: error enviando reminder", [
                'patient_id' => $patient->id,
                'response'   => $response->json(),
            ]);
            throw new \RuntimeException('WhatsApp API error: ' . $response->body());
        }
    }

    // Normalizar teléfono argentino a formato internacional
    // Ejemplos: 1145678901 → 541145678901 | +541145678901 → 541145678901
    private function normalizePhone(string $phone): ?string
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);

        // Argentina: código de país 54
        if (str_starts_with($clean, '54')) {
            return $clean;
        }

        // Números de 10 dígitos (sin código de país)
        if (strlen($clean) === 10) {
            return '54' . $clean;
        }

        return null;
    }
}


// ---- app/Jobs/SyncGoogleCalendar.php ----
namespace App\Jobs;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncGoogleCalendar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private Appointment $appointment,
        private string $action // create | delete
    ) {}

    public function handle(): void
    {
        // Verificar que las credenciales existan
        $credentialsPath = storage_path('app/google-credentials.json');
        if (!file_exists($credentialsPath)) {
            Log::warning('Google Calendar: credenciales no configuradas. Salteando sync.');
            return;
        }

        try {
            $client = new \Google\Client();
            $client->setAuthConfig($credentialsPath);
            $client->addScope(\Google\Service\Calendar::CALENDAR);

            $service    = new \Google\Service\Calendar($client);
            $calendarId = config('services.google.calendar_id');

            if ($this->action === 'create') {
                $this->createEvent($service, $calendarId);
            } elseif ($this->action === 'delete') {
                $this->deleteEvent($service, $calendarId);
            }

        } catch (\Exception $e) {
            Log::error("Google Calendar sync error: " . $e->getMessage(), [
                'appointment_id' => $this->appointment->id,
                'action'         => $this->action,
            ]);
            throw $e; // Re-throw para que el job se reintente
        }
    }

    private function createEvent($service, string $calendarId): void
    {
        $appointment = $this->appointment;
        $patient     = $appointment->patient;
        $tz          = 'America/Argentina/Buenos_Aires';

        $event = new \Google\Service\Calendar\Event([
            'summary'     => "Turno: {$patient->full_name}",
            'description' => "Profesional: {$appointment->professional->name}\nTel: {$patient->phone}",
            'start'       => ['dateTime' => $appointment->starts_at->setTimezone($tz)->toRfc3339String(), 'timeZone' => $tz],
            'end'         => ['dateTime' => $appointment->ends_at->setTimezone($tz)->toRfc3339String(), 'timeZone' => $tz],
            'extendedProperties' => [
                'private' => ['appointment_id' => $appointment->id],
            ],
        ]);

        $created = $service->events->insert($calendarId, $event);

        // Guardar el Google Event ID en notas para poder eliminarlo después
        $appointment->update(['notes' => $appointment->notes . "\ngcal_event_id:{$created->getId()}"]);
    }

    private function deleteEvent($service, string $calendarId): void
    {
        // Extraer Google Event ID guardado en notas
        preg_match('/gcal_event_id:(\S+)/', $this->appointment->notes ?? '', $matches);

        if (empty($matches[1])) {
            return; // Sin ID guardado, nada que eliminar
        }

        try {
            $service->events->delete($calendarId, $matches[1]);
        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() === 404) {
                return; // Ya fue eliminado, no es error
            }
            throw $e;
        }
    }
}
