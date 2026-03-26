<?php

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
