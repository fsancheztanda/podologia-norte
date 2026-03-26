<?php
// app/Services/HolidayService.php
//
// Obtiene feriados nacionales argentinos desde nolaborables.com.ar
// y los cachea en la base de datos.

namespace App\Services;

use App\Models\Holiday;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HolidayService
{
    private const API_URL = 'https://nolaborables.com.ar/api/v2/feriados';

    // ----------------------------------------------------------
    // Sincroniza feriados de un año desde la API pública.
    // Llamar una vez por año o manualmente desde admin.
    // ----------------------------------------------------------
    public function syncYear(int $year): int
    {
        $url      = self::API_URL . "/{$year}";
        $response = Http::timeout(10)->get($url);

        if (!$response->successful()) {
            Log::error("HolidayService: no se pudo obtener feriados para {$year}", [
                'status' => $response->status(),
                'url'    => $url,
            ]);
            throw new \RuntimeException("No se pudieron obtener los feriados para {$year}.");
        }

        $holidays = $response->json();
        $count    = 0;

        foreach ($holidays as $h) {
            // El API devuelve: { "dia": 1, "mes": 1, "motivo": "Año Nuevo", "tipo": "inamovible" }
            $month = str_pad($h['mes'], 2, '0', STR_PAD_LEFT);
            $day   = str_pad($h['dia'], 2, '0', STR_PAD_LEFT);
            $date  = "{$year}-{$month}-{$day}";

            Holiday::updateOrCreate(
                ['date' => $date],
                [
                    'name'          => $h['motivo'] ?? 'Feriado',
                    'type'          => $h['tipo'] ?? null,
                    'year'          => $year,
                    'is_working_day' => false, // por defecto no laborable
                ]
            );

            $count++;
        }

        Log::info("HolidayService: sincronizados {$count} feriados para {$year}");
        return $count;
    }

    // ----------------------------------------------------------
    // Asegura que los feriados del año actual estén cargados.
    // Llamar desde AppServiceProvider o un Command programado.
    // ----------------------------------------------------------
    public function ensureCurrentYear(): void
    {
        $year  = now()->year;
        $count = Holiday::where('year', $year)->count();

        if ($count === 0) {
            try {
                $this->syncYear($year);
            } catch (\Exception $e) {
                Log::warning("HolidayService: no se pudo sincronizar año {$year}: " . $e->getMessage());
            }
        }
    }

    // ----------------------------------------------------------
    // Toggle: marcar feriado como laborable o no laborable
    // ----------------------------------------------------------
    public function toggleWorkingDay(string $date): Holiday
    {
        $holiday = Holiday::findOrFail($date);
        $holiday->update(['is_working_day' => !$holiday->is_working_day]);
        return $holiday;
    }

    // ----------------------------------------------------------
    // Retorna todos los feriados de un mes para mostrar en calendarios
    // ----------------------------------------------------------
    public function getForMonth(int $year, int $month): array
    {
        $monthStr = str_pad($month, 2, '0', STR_PAD_LEFT);

        return Holiday::where('year', $year)
            ->where('date', 'like', "{$year}-{$monthStr}-%")
            ->get()
            ->keyBy(fn ($h) => $h->date->toDateString())
            ->toArray();
    }
}
