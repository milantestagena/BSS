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

        // Bug fixed 2026-09-03 (owner caught it live: Cape Verde had zero guide rows, city or
        // country) — the flat price_per_person_eur column was never the only valid "real price
        // on file" signal, WizardCampaignDestinationWeeklyPrice is an equally real (and newer)
        // alternative; Cape Verde's two cities are weekly-only with no flat fallback (unlike
        // every other priced destination, which happens to carry both), so they silently never
        // qualified here. `hasRealPrice` now checks either.
        $hasRealPrice = fn ($query) => $query->where('wizard_campaign_id', $campaign->id)
            ->where(fn ($q) => $q->whereNotNull('price_per_person_eur')
                ->orWhereHas('weeklyPrices', fn ($w) => $w->whereNotNull('price_per_person_eur')));

        $cities = TaxonomyNode::where('type', 'city')
            ->whereNotNull('meta->vibe_profile')
            ->whereHas('campaignDestinationPrices', $hasRealPrice)
            ->get();

        $countries = TaxonomyNode::where('type', 'country')
            ->whereNotNull('meta->vibe_profile')
            ->whereHas('children.campaignDestinationPrices', $hasRealPrice)
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
