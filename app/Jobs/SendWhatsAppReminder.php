<?php

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

