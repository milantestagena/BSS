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

        // 8 nights * 30 EUR * 1.0 (2 travelers = one 2-person apartment, base rate) = 240, not
        // 9 * 30 * 1.0 = 270. price_per_person_eur is now the PER-APARTMENT rate, not per-head —
        // see WizardCampaignDestinationPrice::roomMultiplierSumFor's docblock, 2026-08-31.
        $this->assertSame(240.0, $total);
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

        // 8 nights * 30 EUR * 1.0 (2 travelers, base apartment rate) = 240.
        $this->assertSame(240.0, $total);
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

        // 8 nights * 24 EUR * 1.0 (2 travelers, base apartment rate) = 192, NOT 0.
        $this->assertSame(192.0, $total);
    }

    /**
     * The root fix for the Rhodes bug, 2026-08-31: accommodation is priced per APARTMENT, not
     * per head. price_per_person_eur is the base rate for a 2-person unit; roomMultiplierSumFor()
     * translates a headcount into a sum of real occupancy multipliers to multiply it by instead
     * of the raw traveler count. Multipliers derived from owner-researched comparison prices
     * (Alanya, Rethymno): compounding ~20% per person above 2 (1.2, 1.44, 1.728 for 3/4/5).
     */
    public function test_solo_traveler_pays_the_same_base_rate_as_a_couple(): void
    {
        // No cheaper "1-person" product exists — a solo traveler still books a full 2-person unit.
        $this->assertSame(1.0, WizardCampaignDestinationPrice::roomMultiplierSumFor(1));
        $this->assertSame(1.0, WizardCampaignDestinationPrice::roomMultiplierSumFor(2));
    }

    public function test_three_travelers_pay_twenty_percent_more_for_one_apartment(): void
    {
        $this->assertSame(1.2, WizardCampaignDestinationPrice::roomMultiplierSumFor(3));
    }

    public function test_four_or_five_travelers_use_the_compounded_rate_only_when_same_unit_requested(): void
    {
        $this->assertSame(1.44, WizardCampaignDestinationPrice::roomMultiplierSumFor(4, sameUnit: true));
        $this->assertSame(1.728, WizardCampaignDestinationPrice::roomMultiplierSumFor(5, sameUnit: true));
    }

    public function test_four_or_five_travelers_split_into_two_apartments_by_default(): void
    {
        // 4 = 2+2 (1.0 + 1.0). 5 = 3+2 (1.2 + 1.0) — cheaper than 2+3 reversed only in naming,
        // same total either way. Default (no answer) behaves exactly like an explicit "no".
        $this->assertSame(2.0, WizardCampaignDestinationPrice::roomMultiplierSumFor(4));
        $this->assertSame(2.0, WizardCampaignDestinationPrice::roomMultiplierSumFor(4, sameUnit: false));
        $this->assertSame(2.2, WizardCampaignDestinationPrice::roomMultiplierSumFor(5));
    }

    public function test_six_or_more_travelers_always_split_even_if_same_unit_is_true(): void
    {
        // sameUnit only ever applies at exactly 4 or 5 — WizardCampaignDestinationPriceTest and
        // the frontend's showRoomsTogetherQuestion agree on that boundary. A stale "true" past 5
        // must never accidentally price a single huge apartment that doesn't exist as a product.
        $this->assertSame(2.4, WizardCampaignDestinationPrice::roomMultiplierSumFor(6, sameUnit: true));
    }

    public function test_room_splitting_avoids_a_lone_leftover_of_one(): void
    {
        // 6 = 3+3 (2.4). 7 = 3+2+2 (3.2, not 3+3+1 which would need a 4th 1-person unit). 8 =
        // 3+3+2 (3.4). Owner-verified against these exact three examples, 2026-08-31.
        $this->assertSame(2.4, WizardCampaignDestinationPrice::roomMultiplierSumFor(6));
        $this->assertSame(3.2, WizardCampaignDestinationPrice::roomMultiplierSumFor(7));
        $this->assertSame(3.4, WizardCampaignDestinationPrice::roomMultiplierSumFor(8));
    }

    public function test_wants_same_unit_only_true_for_four_or_five_travelers_answered_yes(): void
    {
        $this->assertTrue(WizardCampaignDestinationPrice::wantsSameUnit(4, 1));
        $this->assertTrue(WizardCampaignDestinationPrice::wantsSameUnit(5, 1));
        $this->assertFalse(WizardCampaignDestinationPrice::wantsSameUnit(4, 2));
        $this->assertFalse(WizardCampaignDestinationPrice::wantsSameUnit(4, null));
        $this->assertFalse(WizardCampaignDestinationPrice::wantsSameUnit(3, 1));
        $this->assertFalse(WizardCampaignDestinationPrice::wantsSameUnit(6, 1));
    }

    public function test_estimate_accommodation_total_uses_same_unit_flag_end_to_end(): void
    {
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'test', 'sort_order' => 0]);
        $campaign = WizardCampaign::create(['key' => 'testcamp', 'label' => 'test', 'is_active' => true, 'sort_order' => 0]);
        $price = WizardCampaignDestinationPrice::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $city->id, 'price_per_person_eur' => 50,
        ]);

        // 6 nights, 4 travelers, same unit requested: 6 * 50 * 1.44 = 432.
        $together = $price->estimateAccommodationTotal(Carbon::parse('2026-09-05'), Carbon::parse('2026-09-11'), 4, true);
        $this->assertSame(432.0, $together);

        // Same trip, split into 2+2 instead: 6 * 50 * 2.0 = 600.
        $split = $price->estimateAccommodationTotal(Carbon::parse('2026-09-05'), Carbon::parse('2026-09-11'), 4, false);
        $this->assertSame(600.0, $split);
    }
}
