<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over Open-Meteo's free, no-key APIs — used to populate TaxonomyNodeClimate rows
 * with real historical data instead of manual_estimate guesses. See wizard_architecture,
 * 2026-07-13. Two separate endpoints because air climate and sea temperature are different
 * Open-Meteo products (Historical Weather / Archive API vs Marine API).
 */
class OpenMeteoClient
{
    /**
     * Monthly air climate (avg temp, total precipitation, total sunshine hours) for a full
     * calendar year at the given coordinates, keyed by month (1-12). Aggregated client-side
     * from Open-Meteo's daily archive data — the API itself only returns daily values.
     */
    public function monthlyClimate(float $lat, float $lng, int $year): array
    {
        $response = Http::get('https://archive-api.open-meteo.com/v1/archive', [
            'latitude' => $lat,
            'longitude' => $lng,
            'start_date' => "{$year}-01-01",
            'end_date' => "{$year}-12-31",
            'daily' => 'temperature_2m_mean,precipitation_sum,sunshine_duration',
            'timezone' => 'UTC',
        ])->throw()->json();

        return $this->aggregateByMonth($response['daily'], function (Collection $days) {
            return [
                'avg_temp_c' => round($days->avg('temperature_2m_mean'), 1),
                'precip_mm' => round($days->sum('precipitation_sum'), 1),
                'sun_hours' => round($days->sum('sunshine_duration') / 3600, 1),
            ];
        });
    }

    /**
     * Monthly average sea surface temperature for a full calendar year, keyed by month.
     * Returns an empty array for inland coordinates — Open-Meteo's marine model has no data
     * there, not an error (callers should treat a missing month as "not coastal", not fail).
     */
    public function monthlySeaTemp(float $lat, float $lng, int $year): array
    {
        $response = Http::get('https://marine-api.open-meteo.com/v1/marine', [
            'latitude' => $lat,
            'longitude' => $lng,
            'start_date' => "{$year}-01-01",
            'end_date' => "{$year}-12-31",
            'daily' => 'sea_surface_temperature_mean',
        ])->throw()->json();

        if (empty($response['daily']['sea_surface_temperature_mean']) || collect($response['daily']['sea_surface_temperature_mean'])->filter()->isEmpty()) {
            return [];
        }

        return $this->aggregateByMonth($response['daily'], function (Collection $days) {
            $temps = $days->pluck('sea_surface_temperature_mean')->filter();

            return $temps->isEmpty() ? [] : ['sea_temp_c' => round($temps->avg(), 1)];
        });
    }

    /**
     * Open-Meteo returns parallel arrays (time[], value1[], value2[], ...), not row objects —
     * zip them into rows, group by calendar month, then let the caller reduce each month's rows
     * however it needs (avg for temperature, sum for precipitation/sunshine).
     */
    private function aggregateByMonth(array $daily, callable $reducer): array
    {
        $time = $daily['time'];
        unset($daily['time']);

        $rows = collect($time)->map(function ($date, $i) use ($daily) {
            $row = ['date' => $date];
            foreach ($daily as $field => $values) {
                $row[$field] = $values[$i];
            }

            return $row;
        });

        return $rows->groupBy(fn ($row) => (int) date('n', strtotime($row['date'])))
            ->map($reducer)
            ->filter()
            ->all();
    }
}
