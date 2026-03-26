<?php

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
