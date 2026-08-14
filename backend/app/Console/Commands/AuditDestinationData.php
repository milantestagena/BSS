<?php

namespace App\Console\Commands;

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

        $mediteran = TaxonomyNode::where('type', 'region_theme')->where('slug', 'mediteran')->first();
        if (! $mediteran) {
            $this->error("No 'mediteran' region_theme found.");

            return self::FAILURE;
        }

        $countries = TaxonomyNode::where('type', 'country')->where('parent_id', $mediteran->id)->orderBy('label')->get();

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
            ];
        }
        $this->table(['Country', 'Meal price', 'Coffee price', 'Meal-plan coefficient'], $countryRows);

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
                $hasPrice = $priceRow && (
                    $priceRow->price_per_person_eur !== null
                    || $priceRow->weeklyPrices()->exists()
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

        return self::SUCCESS;
    }
}
