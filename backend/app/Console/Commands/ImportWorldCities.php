<?php

namespace App\Console\Commands;

use App\Models\WorldCity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Imports GeoNames' cities15000 dump (~34k cities, population > 15000, CC BY 4.0) into
 * world_cities — powers the home_city typeahead. See wizard_architecture memory, 2026-08-03.
 * Safely re-runnable: upserts by geoname_id, never duplicates.
 */
class ImportWorldCities extends Command
{
    protected $signature = 'cities:import';

    protected $description = 'Import GeoNames cities15000 dump into world_cities';

    public function handle(): int
    {
        $this->info('Downloading cities15000.zip from GeoNames...');

        $zipPath = storage_path('app/cities15000.zip');
        $response = Http::timeout(60)->get('https://download.geonames.org/export/dump/cities15000.zip');

        if (! $response->ok()) {
            $this->error('Download failed: HTTP '.$response->status());

            return self::FAILURE;
        }

        file_put_contents($zipPath, $response->body());

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            $this->error('Could not open downloaded zip.');

            return self::FAILURE;
        }
        $zip->extractTo(storage_path('app'));
        $zip->close();
        unlink($zipPath);

        $txtPath = storage_path('app/cities15000.txt');
        $handle = fopen($txtPath, 'r');

        $this->info('Parsing and importing...');
        $rows = [];
        $count = 0;

        while (($line = fgets($handle)) !== false) {
            // GeoNames tab-separated columns: geonameid, name, asciiname, alternatenames,
            // latitude, longitude, feature class, feature code, country code, cc2, admin1,
            // admin2, admin3, admin4, population, elevation, dem, timezone, modification date.
            $cols = explode("\t", $line);
            if (count($cols) < 15) {
                continue;
            }

            $rows[] = [
                'geoname_id' => (int) $cols[0],
                'name' => $cols[1],
                'ascii_name' => $cols[2],
                'lat' => (float) $cols[4],
                'lng' => (float) $cols[5],
                'country_code' => $cols[8],
                'population' => (int) $cols[14],
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($rows) >= 1000) {
                WorldCity::upsert($rows, ['geoname_id'], ['name', 'ascii_name', 'lat', 'lng', 'country_code', 'population', 'updated_at']);
                $count += count($rows);
                $rows = [];
                $this->output->write('.');
            }
        }

        if ($rows) {
            WorldCity::upsert($rows, ['geoname_id'], ['name', 'ascii_name', 'lat', 'lng', 'country_code', 'population', 'updated_at']);
            $count += count($rows);
        }

        fclose($handle);
        unlink($txtPath);

        $this->newLine();
        $this->info("Imported {$count} cities.");

        return self::SUCCESS;
    }
}
