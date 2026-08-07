<?php

namespace App\Console\Commands;

use App\Models\TaxonomyNode;
use App\Models\TaxonomyNodeClimate;
use App\Services\OpenMeteoClient;
use Illuminate\Console\Command;

/**
 * Replaces manual_estimate climate rows with real historical data from Open-Meteo — see
 * OpenMeteoClient and wizard_architecture memory, 2026-07-30. Runs for every taxonomy node
 * (city or country) that has `meta.lat`/`meta.lng`, all 12 months at once, so themes aren't
 * limited to whichever 3 months someone manually estimated (this is what surfaced the gap:
 * the swim destinations only had Oct/Nov/Dec seeded, so a September recommendation silently
 * had no climate signal to show).
 */
class ImportClimateData extends Command
{
    protected $signature = 'climate:import {--year=2025 : Which past calendar year to pull historical data for}';

    protected $description = 'Import real 12-month climate data (air + sea temp) from Open-Meteo for every taxonomy node with coordinates';

    public function handle(OpenMeteoClient $client): int
    {
        $year = (int) $this->option('year');

        $nodes = TaxonomyNode::whereIn('type', ['city', 'country'])
            ->whereNotNull('meta->lat')
            ->whereNotNull('meta->lng')
            ->get();

        if ($nodes->isEmpty()) {
            $this->warn('No taxonomy nodes with meta.lat/meta.lng found.');

            return self::SUCCESS;
        }

        $this->info("Importing {$year} climate for {$nodes->count()} location(s)...");
        $failures = [];

        $this->withProgressBar($nodes, function (TaxonomyNode $node) use ($client, $year, &$failures) {
            try {
                $lat = (float) $node->meta['lat'];
                $lng = (float) $node->meta['lng'];

                $air = $client->monthlyClimate($lat, $lng, $year);
                $sea = $client->monthlySeaTemp($lat, $lng, $year);

                foreach (range(1, 12) as $month) {
                    if (! isset($air[$month])) {
                        continue;
                    }

                    TaxonomyNodeClimate::updateOrCreate(
                        ['taxonomy_node_id' => $node->id, 'month' => $month],
                        [
                            'avg_temp_c' => $air[$month]['avg_temp_c'] ?? null,
                            'precip_mm' => $air[$month]['precip_mm'] ?? null,
                            'sun_hours' => $air[$month]['sun_hours'] ?? null,
                            'sea_temp_c' => $sea[$month]['sea_temp_c'] ?? null,
                            'source' => "open-meteo:{$year}",
                        ]
                    );
                }
            } catch (\Throwable $e) {
                $failures[] = "{$node->slug}: {$e->getMessage()}";
            }
        });

        $this->newLine(2);

        if ($failures) {
            $this->warn(count($failures).' location(s) failed:');
            foreach ($failures as $failure) {
                $this->line("  - {$failure}");
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
