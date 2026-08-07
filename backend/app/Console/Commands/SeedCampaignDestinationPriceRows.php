<?php

namespace App\Console\Commands;

use App\Models\TaxonomyNode;
use App\Models\WizardCampaign;
use App\Models\WizardCampaignDestinationPrice;
use Illuminate\Console\Command;

/**
 * Pre-creates one empty (price_per_person_eur = null) row per swim destination for a
 * campaign, turning the Filament admin table into a fill-in-the-blanks grid instead of
 * one-at-a-time record creation — see wizard_architecture memory, 2026-08-05.
 *
 * Idempotent via firstOrCreate — safe to re-run after adding new destinations (e.g. the
 * Lampedusa/Linosa addition) without touching prices already entered for existing ones.
 * Destinations are every `city` node carrying a `vibe_profile` (the same 37 seeded
 * 2026-08-04) rather than hardcoding country slugs again.
 */
class SeedCampaignDestinationPriceRows extends Command
{
    protected $signature = 'campaign:seed-destination-price-rows {campaignKey}';

    protected $description = 'Pre-create empty destination price rows for a campaign, ready to fill in via Filament';

    public function handle(): int
    {
        $campaign = WizardCampaign::where('key', $this->argument('campaignKey'))->first();
        if (! $campaign) {
            $this->error("No campaign with key '{$this->argument('campaignKey')}'.");

            return self::FAILURE;
        }

        $cities = TaxonomyNode::where('type', 'city')->whereNotNull('meta->vibe_profile')->get();

        $created = 0;
        foreach ($cities as $city) {
            $row = WizardCampaignDestinationPrice::firstOrCreate([
                'wizard_campaign_id' => $campaign->id,
                'taxonomy_node_id' => $city->id,
            ]);
            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->info("{$created} new row(s) created for '{$campaign->key}' ({$cities->count()} destinations total).");

        return self::SUCCESS;
    }
}
