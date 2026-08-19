<?php

namespace App\Console\Commands;

use App\Models\DestinationGuide;
use App\Models\TaxonomyNode;
use App\Models\WizardCampaign;
use Illuminate\Console\Command;

/**
 * Pre-creates one empty DestinationGuide row per real, already-priced destination for a
 * campaign — same "scan-and-fill" idea as campaign:seed-destination-price-rows, one level
 * later in the pipeline. Deliberately does NOT write any content itself (no generic "filler"
 * command, see DestinationGuide's class docblock for the actual research checklist) — this
 * only scaffolds rows so there's something to fill in.
 *
 * A destination only qualifies once it has a REAL, non-null campaign price on file — writing
 * guide content (cost section, itinerary reality-check) before real pricing exists would be
 * premature, unlike campaign:seed-destination-price-rows' own bar (vibe_profile alone), which
 * runs earlier in the pipeline before prices exist at all.
 */
class SeedDestinationGuideRows extends Command
{
    protected $signature = 'campaign:seed-destination-guide-rows {campaignKey}';

    protected $description = 'Pre-create empty destination guide rows for a campaign, for already-priced destinations only';

    public function handle(): int
    {
        $campaign = WizardCampaign::where('key', $this->argument('campaignKey'))->first();
        if (! $campaign) {
            $this->error("No campaign with key '{$this->argument('campaignKey')}'.");

            return self::FAILURE;
        }

        $cities = TaxonomyNode::where('type', 'city')
            ->whereNotNull('meta->vibe_profile')
            ->whereHas('campaignDestinationPrices', fn ($q) => $q->where('wizard_campaign_id', $campaign->id)->whereNotNull('price_per_person_eur'))
            ->get();

        $countries = TaxonomyNode::where('type', 'country')
            ->whereNotNull('meta->vibe_profile')
            ->whereHas('children.campaignDestinationPrices', fn ($q) => $q->where('wizard_campaign_id', $campaign->id)->whereNotNull('price_per_person_eur'))
            ->get();

        $destinations = $cities->concat($countries);

        $created = 0;
        foreach ($destinations as $destination) {
            $row = DestinationGuide::firstOrCreate([
                'wizard_campaign_id' => $campaign->id,
                'taxonomy_node_id' => $destination->id,
            ]);
            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->info("{$created} new row(s) created for '{$campaign->key}' ({$destinations->count()} priced destinations total).");

        return self::SUCCESS;
    }
}
