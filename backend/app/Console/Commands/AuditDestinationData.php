<?php

namespace App\Console\Commands;

use App\Models\DestinationGuide;
use App\Models\TaxonomyNode;
use App\Models\WizardCampaign;
use Illuminate\Console\Command;

/**
 * Owner's ask, 2026-08-14: "da bi mogli lepo da istestiramo - da vidimo sta kom gradu fali" —
 * a plain-text gap report over the whole active swim geography (not just the curated
 * vibe_profile subset SeedCampaignDestinationPriceRows scopes to), so testing tomorrow starts
 * from a real punch list instead of stumbling into gaps city by city inside the wizard.
 *
 * Per-city: vibe_profile (hover description), a real filled-in campaign price (flat or at
 * least one weekly row), climate data. Per-country (checked once, shown separately since it
 * doesn't vary by city): hospitality meal/coffee prices (feeds BudgetEstimationEngine
 * entirely), meal_plan_coefficient (still on the 0.8 default vs a real calibrated one — see
 * MealPlanCoefficientCalculator).
 */
class AuditDestinationData extends Command
{
    protected $signature = 'campaign:audit-destinations {campaignKey=kasno-letovanje}';

    protected $description = 'Report which cities/countries in the active swim geography are missing vibe_profile, price, climate, or hospitality/meal-plan data';

    public function handle(): int
    {
        $campaign = WizardCampaign::where('key', $this->argument('campaignKey'))->first();
        if (! $campaign) {
            $this->error("No campaign with key '{$this->argument('campaignKey')}'.");

            return self::FAILURE;
        }

        // Bug fixed 2026-08-14: this used to resolve countries via `parent_id = mediteran`,
        // which silently excluded Greece and Italy (and every one of their ~30 cities) from
        // the WHOLE audit — both are country nodes REUSED from the old city-break taxonomy
        // (parent_id still points to the old 'anticki_svet' region_theme, never reparented when
        // the swim campaign added children under them), even though they're fully live,
        // selectable countries in the real wizard. Resolving by "has at least one city with a
        // vibe_profile" instead — the same real-membership signal
        // campaign:seed-destination-price-rows already uses for its own city scoping — sidesteps
        // the region_theme parentage question entirely instead of guessing at another one.
        $countries = TaxonomyNode::where('type', 'country')
            ->whereHas('children', fn ($q) => $q->where('type', 'city')->whereNotNull('meta->vibe_profile'))
            ->orderBy('label')
            ->get();

        $this->info('=== Per-country: hospitality & meal-plan data ===');
        $countryRows = [];
        foreach ($countries as $country) {
            $hospitality = $country->meta['hospitality'] ?? null;
            $coefficient = $country->meta['meal_plan_coefficient'] ?? null;
            $countryRows[] = [
                $country->label,
                $hospitality['avg_restaurant_meal_eur'] ?? null ? '✓' : '✗ MISSING',
                $hospitality['avg_cafe_coffee_eur'] ?? null ? '✓' : '✗ MISSING',
                $coefficient !== null ? (string) $coefficient : '— (0.8 default)',
                $this->hasRealGuideContent($country, $campaign) ? '✓' : '—',
            ];
        }
        $this->table(['Country', 'Meal price', 'Coffee price', 'Meal-plan coefficient', 'Guide'], $countryRows);

        $this->newLine();
        $this->info('=== Per-city: vibe_profile, campaign price, climate ===');

        $cityRows = [];
        $missingVibe = 0;
        $missingPrice = 0;
        $missingClimate = 0;

        foreach ($countries as $country) {
            $cities = TaxonomyNode::where('type', 'city')->where('parent_id', $country->id)->orderBy('label')->get();

            foreach ($cities as $city) {
                $hasVibe = ! empty($city->meta['vibe_profile']['description'] ?? null);

                $priceRow = $city->campaignDestinationPrices()->where('wizard_campaign_id', $campaign->id)->first();
                // Bug fixed 2026-08-14 (same one as WizardCampaignDestinationPrice::
                // estimateAccommodationTotal): a weekly row EXISTING (pre-created empty) isn't
                // the same as it having a real price — this used to report "price ✓" for cities
                // whose weekly prices were all still null, hiding the exact gap this audit
                // exists to catch.
                $hasPrice = $priceRow && (
                    $priceRow->price_per_person_eur !== null
                    || $priceRow->weeklyPrices()->whereNotNull('price_per_person_eur')->exists()
                );

                $hasClimate = $city->climateMonths()->exists();

                if (! $hasVibe) $missingVibe++;
                if (! $hasPrice) $missingPrice++;
                if (! $hasClimate) $missingClimate++;

                // Only print rows with at least one gap — a clean city doesn't need to clutter
                // the punch list, that's the whole point of an audit over a flat dump.
                if ($hasVibe && $hasPrice && $hasClimate) {
                    continue;
                }

                $cityRows[] = [
                    $country->label,
                    $city->label,
                    $hasVibe ? '✓' : '✗',
                    $hasPrice ? '✓' : '✗',
                    $hasClimate ? '✓' : '✗',
                ];
            }
        }

        if (empty($cityRows)) {
            $this->info('No gaps — every city has vibe_profile, a campaign price, and climate data.');
        } else {
            $this->table(['Country', 'City', 'Vibe profile', 'Price', 'Climate'], $cityRows);
        }

        $totalCities = TaxonomyNode::where('type', 'city')->whereIn('parent_id', $countries->pluck('id'))->count();
        $this->newLine();
        $this->info("Totals across {$totalCities} cities: {$missingVibe} missing vibe_profile, {$missingPrice} missing a real price, {$missingClimate} missing climate data.");

        // Guide content, 2026-08-19 — a separate lightweight summary, not folded into the gap
        // table above: guides are optional enrichment (only ever written for already-priced
        // destinations, see SeedDestinationGuideRows), so treating "no guide yet" the same as a
        // real gap would flood the punch list with rows that don't need fixing before launch.
        $pricedCities = TaxonomyNode::where('type', 'city')
            ->whereIn('parent_id', $countries->pluck('id'))
            ->whereHas('campaignDestinationPrices', fn ($q) => $q->where('wizard_campaign_id', $campaign->id)->whereNotNull('price_per_person_eur'))
            ->get();
        $guidedCount = $pricedCities->concat($countries)
            ->filter(fn (TaxonomyNode $node) => $this->hasRealGuideContent($node, $campaign))
            ->count();
        $this->info("Guides: {$guidedCount} of ".($pricedCities->count() + $countries->count())." priced destinations (cities + countries) have a written guide.");

        return self::SUCCESS;
    }

    /** A scaffolded-but-empty DestinationGuide row doesn't count — same "row exists vs. row has
     *  real content" distinction already applied to price/vibe_profile checks above. */
    private function hasRealGuideContent(TaxonomyNode $node, WizardCampaign $campaign): bool
    {
        $guide = DestinationGuide::where('wizard_campaign_id', $campaign->id)
            ->where('taxonomy_node_id', $node->id)
            ->first();

        return $guide !== null && (
            ! empty($guide->itinerary)
            || ! empty($guide->accommodation_cost_notes)
            || ! empty($guide->extra_tips)
            || ! empty($guide->images)
        );
    }
}
