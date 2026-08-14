<?php

namespace Tests\Unit;

use App\Models\TaxonomyNode;
use App\Models\WizardCampaign;
use App\Models\WizardCampaignDestinationPrice;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the night-counting bug caught live by the owner, 2026-08-12: check-in
 * Sep 19 / check-out Sep 27 is 8 NIGHTS (19-26 slept, checkout morning of the 27th — no night
 * charged for the 27th itself), not 9. The accommodation total had been using the same
 * diffInDays+1 convention as the FOOD estimate (which correctly counts the checkout day, since
 * you still eat that day) — copied over by mistake. See GeographyResolver::tripDurationNights
 * for the matching fix on the country-candidate-filtering side.
 */
class WizardCampaignDestinationPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_flat_price_charges_nights_not_calendar_days_present(): void
    {
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'test', 'sort_order' => 0]);
        $campaign = WizardCampaign::create(['key' => 'testcamp', 'label' => 'test', 'is_active' => true, 'sort_order' => 0]);
        $price = WizardCampaignDestinationPrice::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $city->id, 'price_per_person_eur' => 30,
        ]);

        $total = $price->estimateAccommodationTotal(Carbon::parse('2026-09-19'), Carbon::parse('2026-09-27'), 2);

        // 8 nights * 30 EUR * 2 travelers = 480, not 9 * 30 * 2 = 540.
        $this->assertSame(480.0, $total);
    }

    public function test_weekly_price_splits_across_nights_not_calendar_days_present(): void
    {
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'test', 'sort_order' => 0]);
        $campaign = WizardCampaign::create([
            'key' => 'testcamp', 'label' => 'test', 'is_active' => true, 'sort_order' => 0,
            'season_start_date' => '2026-08-29', 'season_end_date' => '2026-11-01',
        ]);
        $price = WizardCampaignDestinationPrice::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $city->id,
        ]);
        // Single flat rate across the whole stay so the assertion is a simple nights*rate check.
        $price->weeklyPrices()->create(['week_start_date' => '2026-09-19', 'price_per_person_eur' => 30]);
        $price->weeklyPrices()->create(['week_start_date' => '2026-09-26', 'price_per_person_eur' => 30]);

        $total = $price->estimateAccommodationTotal(Carbon::parse('2026-09-19'), Carbon::parse('2026-09-27'), 2);

        $this->assertSame(480.0, $total);
    }

    public function test_cheapest_nightly_rate_does_not_look_past_checkout_night(): void
    {
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'test', 'sort_order' => 0]);
        // Week boundaries land on Sep 13, Sep 20, Sep 27 — checkout (Sep 27) falls EXACTLY on a
        // week start, so the 8 real nights (19-26) fall entirely within the Sep 13/Sep 20 weeks;
        // nothing is actually slept in the Sep 27 week.
        $campaign = WizardCampaign::create([
            'key' => 'testcamp', 'label' => 'test', 'is_active' => true, 'sort_order' => 0,
            'season_start_date' => '2026-09-13', 'season_end_date' => '2026-11-01',
        ]);
        $price = WizardCampaignDestinationPrice::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $city->id,
        ]);
        $price->weeklyPrices()->create(['week_start_date' => '2026-09-13', 'price_per_person_eur' => 30]);
        $price->weeklyPrices()->create(['week_start_date' => '2026-09-20', 'price_per_person_eur' => 30]);
        // The trap: a cheap rate sitting in the checkout day's own week — must never be picked
        // up, since no night is actually slept there.
        $price->weeklyPrices()->create(['week_start_date' => '2026-09-27', 'price_per_person_eur' => 5]);

        $rate = $price->cheapestNightlyRateFor(Carbon::parse('2026-09-19'), Carbon::parse('2026-09-27'));

        $this->assertSame(30.0, $rate);
    }

    public function test_falls_back_to_flat_price_when_weekly_rows_exist_but_are_all_still_empty(): void
    {
        // Real bug caught live 2026-08-14: campaign:seed-destination-weekly-price-rows
        // pre-creates a weekly row per season week with a NULL price, ready to fill in later.
        // estimateAccommodationTotal used to check "do any weekly ROWS exist" to decide whether
        // to fall back to the flat price_per_person_eur — rows existing (even all-empty) meant
        // it never fell back, so a destination with a real flat price but still-unfilled weekly
        // rows silently totaled 0.0 instead. Rodos was exactly this case in production: real
        // flat price entered, 10 empty weekly placeholder rows, total came back 0.
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'test', 'sort_order' => 0]);
        $campaign = WizardCampaign::create([
            'key' => 'testcamp', 'label' => 'test', 'is_active' => true, 'sort_order' => 0,
            'season_start_date' => '2026-08-29', 'season_end_date' => '2026-11-01',
        ]);
        $price = WizardCampaignDestinationPrice::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $city->id, 'price_per_person_eur' => 24,
        ]);
        // Pre-created, still empty — exactly what the seeder leaves behind before anyone fills them in.
        $price->weeklyPrices()->create(['week_start_date' => '2026-09-19', 'price_per_person_eur' => null]);
        $price->weeklyPrices()->create(['week_start_date' => '2026-09-26', 'price_per_person_eur' => null]);

        $total = $price->estimateAccommodationTotal(Carbon::parse('2026-09-19'), Carbon::parse('2026-09-27'), 2);

        // 8 nights * 24 EUR * 2 travelers = 384, NOT 0.
        $this->assertSame(384.0, $total);
    }
}
