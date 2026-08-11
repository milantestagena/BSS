<?php

namespace App\Console\Commands;

use App\Models\WizardCampaign;
use App\Models\WizardCampaignDestinationWeeklyPrice;
use Illuminate\Console\Command;

/**
 * Pre-creates one empty (price_per_person_eur = null) weekly row per (existing destination
 * price row) x (campaign's seasonWeeks()) — same fill-in-the-blanks philosophy as
 * SeedCampaignDestinationPriceRows, just one dimension deeper. Requires the campaign to have
 * `season_start_date`/`season_end_date` set (see WizardCampaign::seasonWeeks()) and destination
 * price rows to already exist (run campaign:seed-destination-price-rows first).
 *
 * Idempotent via firstOrCreate — safe to re-run after adding a new destination or extending the
 * season without touching weekly prices already entered.
 */
class SeedCampaignWeeklyPriceRows extends Command
{
    protected $signature = 'campaign:seed-weekly-price-rows {campaignKey}';

    protected $description = 'Pre-create empty weekly price rows (per destination x week) for a campaign';

    public function handle(): int
    {
        $campaign = WizardCampaign::where('key', $this->argument('campaignKey'))->first();
        if (! $campaign) {
            $this->error("No campaign with key '{$this->argument('campaignKey')}'.");

            return self::FAILURE;
        }

        $weeks = $campaign->seasonWeeks();
        if ($weeks->isEmpty()) {
            $this->error("Campaign '{$campaign->key}' has no season_start_date/season_end_date set.");

            return self::FAILURE;
        }

        $destinationPrices = $campaign->destinationPrices;
        if ($destinationPrices->isEmpty()) {
            $this->error("No destination price rows for '{$campaign->key}' yet — run campaign:seed-destination-price-rows first.");

            return self::FAILURE;
        }

        $created = 0;
        foreach ($destinationPrices as $destinationPrice) {
            foreach ($weeks as $weekStart) {
                $row = WizardCampaignDestinationWeeklyPrice::firstOrCreate([
                    'wizard_campaign_destination_price_id' => $destinationPrice->id,
                    'week_start_date' => $weekStart->toDateString(),
                ]);
                if ($row->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        $this->info("{$created} new weekly row(s) created ({$destinationPrices->count()} destinations x {$weeks->count()} weeks).");

        return self::SUCCESS;
    }
}
