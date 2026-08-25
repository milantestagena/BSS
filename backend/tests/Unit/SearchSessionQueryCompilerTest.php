<?php

namespace Tests\Unit;

use App\Models\Location;
use App\Models\SearchSession;
use App\Models\TaxonomyNode;
use App\Models\TaxonomyNodeClimate;
use App\Services\SearchSessionQueryCompiler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers SearchSessionQueryCompiler — see wizard_architecture memory, 2026-07-30. Two
 * concerns: real Booking params (toBookingParams) and Honest Report signals
 * (toHonestReportSignals), including the recommended-dates fallback and the generic
 * honest_report_thresholds climate caveat mechanism.
 */
class SearchSessionQueryCompilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_params_stay_absent_for_unanswered_fields(): void
    {
        $session = SearchSession::create(['status' => 'in_progress']);

        $params = (new SearchSessionQueryCompiler($session))->toBookingParams();

        $this->assertArrayNotHasKey('checkin', $params);
        $this->assertArrayNotHasKey('guests', $params);
        $this->assertArrayNotHasKey('location', $params);
    }

    public function test_booking_params_use_explicit_dates_when_set(): void
    {
        $session = SearchSession::create([
            'status' => 'in_progress',
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-08',
            'adults_count' => 2,
        ]);

        $params = (new SearchSessionQueryCompiler($session))->toBookingParams();

        $this->assertSame('2026-10-01', $params['checkin']);
        $this->assertSame('2026-10-08', $params['checkout']);
        $this->assertSame(2, $params['guests']['number_of_adults']);
    }

    public function test_booking_params_recommend_dates_from_termin_category_window_when_none_given(): void
    {
        Carbon::setTestNow('2026-07-30');

        TaxonomyNode::create([
            'type' => 'termin_category', 'slug' => 'kasno_kupanje', 'label' => 'test', 'sort_order' => 0,
            'meta' => ['window_start' => '09-20', 'default_duration_days' => 5],
        ]);

        $session = SearchSession::create(['status' => 'in_progress', 'termin_category' => 'kasno_kupanje']);

        $params = (new SearchSessionQueryCompiler($session))->toBookingParams();

        $this->assertSame('2026-09-20', $params['checkin']);
        $this->assertSame('2026-09-25', $params['checkout']);

        Carbon::setTestNow();
    }

    public function test_booking_params_include_budget_tier_price_range(): void
    {
        $budgetTier = TaxonomyNode::create([
            'type' => 'budget_tier', 'slug' => 'do_20e', 'label' => 'test', 'sort_order' => 0,
            'meta' => ['min' => 0, 'max' => 20, 'currency' => 'EUR'],
        ]);
        $session = SearchSession::create(['status' => 'in_progress', 'budget_tier_id' => $budgetTier->id]);

        $params = (new SearchSessionQueryCompiler($session))->toBookingParams();

        $this->assertSame(0, $params['filters']['price']['minimum']);
        $this->assertSame(20, $params['filters']['price']['maximum']);
    }

    public function test_booking_params_include_real_location_id_via_booking_location(): void
    {
        $location = Location::create(['booking_dest_id' => 'test_123', 'dest_type' => 'city', 'name' => 'Test City', 'source' => 'manual_test']);
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'test', 'sort_order' => 0, 'booking_location_id' => $location->id]);
        $session = SearchSession::create(['status' => 'in_progress', 'city_id' => $city->id]);

        $params = (new SearchSessionQueryCompiler($session))->toBookingParams();

        $this->assertSame('test_123', $params['location']);
    }

    public function test_booking_url_is_null_without_a_destination_or_dates(): void
    {
        $session = SearchSession::create(['status' => 'in_progress']);

        $this->assertNull((new SearchSessionQueryCompiler($session))->toBookingUrl());
    }

    /** Bug fixed 2026-08-18: this used to be at risk of reusing toBookingParams()['location'],
     *  which reads Location::booking_dest_id — seeded as placeholder `test_*_city` strings back
     *  in the pre-swim-campaign era, never replaced with real Booking dest_ids. `ss=` (plain
     *  destination search string) needs no dest_id lookup at all. */
    public function test_booking_url_uses_ss_search_string_not_a_dest_id(): void
    {
        $country = TaxonomyNode::create(['type' => 'country', 'slug' => 'turska', 'label' => 'Turkey', 'sort_order' => 0]);
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'antalija', 'label' => 'Antalya', 'sort_order' => 0, 'parent_id' => $country->id]);

        $session = SearchSession::create([
            'status' => 'in_progress',
            'city_id' => $city->id,
            'date_from' => '2026-09-20',
            'date_to' => '2026-09-27',
            'adults_count' => 2,
            'number_of_rooms' => 1,
        ]);

        $url = (new SearchSessionQueryCompiler($session))->toBookingUrl();

        $this->assertStringStartsWith('https://www.booking.com/searchresults.html?', $url);
        $this->assertStringContainsString('ss='.rawurlencode('Antalya, Turkey'), $url);
        $this->assertStringContainsString('checkin=2026-09-20', $url);
        $this->assertStringContainsString('checkout=2026-09-27', $url);
        $this->assertStringContainsString('group_adults=2', $url);
        $this->assertStringContainsString('no_rooms=1', $url);
        $this->assertStringNotContainsString('dest_id', $url);
    }

    /** Confirmed live 2026-08-21 — see config/services.php's 'cj' block docblock for how pid/
     *  link_id were obtained (CJ dashboard's link-builder, Destination Url override, clicked
     *  through to a real Booking.com search-results page). Every OTHER test in this file runs
     *  with no CJ config set, so they already cover the unwrapped fallback path. */
    public function test_booking_url_wraps_with_cj_affiliate_tracking_when_configured(): void
    {
        config(['services.cj.pid' => '101857480', 'services.cj.link_id' => '15734849']);

        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'antalija3', 'label' => 'Antalya', 'sort_order' => 0]);
        $session = SearchSession::create([
            'status' => 'in_progress',
            'city_id' => $city->id,
            'date_from' => '2026-09-20',
            'date_to' => '2026-09-27',
            'adults_count' => 2,
        ]);

        $url = (new SearchSessionQueryCompiler($session))->toBookingUrl();

        $this->assertStringStartsWith('https://www.dpbolvw.net/click-101857480-15734849?url=', $url);
        $this->assertStringContainsString(rawurlencode('https://www.booking.com/searchresults.html?'), $url);
        $this->assertStringContainsString(rawurlencode('ss=Antalya'), $url);
    }

    public function test_booking_url_includes_repeated_age_params_for_each_child(): void
    {
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'antalija2', 'label' => 'Antalya', 'sort_order' => 0]);
        $session = SearchSession::create([
            'status' => 'in_progress',
            'city_id' => $city->id,
            'date_from' => '2026-09-20',
            'date_to' => '2026-09-27',
            'adults_count' => 2,
            'children_ages' => [5, 9],
        ]);

        $url = (new SearchSessionQueryCompiler($session))->toBookingUrl();

        $this->assertStringContainsString('group_children=2', $url);
        $this->assertStringContainsString('age=5', $url);
        $this->assertStringContainsString('age=9', $url);
    }

    /** Bug fixed 2026-08-23 (owner caught it live: picked amenities/meal plan/cheap-sort in the
     *  wizard, but the real Booking.com link showed none of it applied) — toBookingUrl() now
     *  reuses toBookingParams()'s filter computation and translates it into Booking's real
     *  `nflt`/`order`/`family_friendly_property` URL parameters. IDs and format here match a
     *  real captured Booking.com search URL the owner sent the same day (WiFi=107, private
     *  bathroom=38, and all four real meal-plan IDs), not guessed. */
    public function test_booking_url_includes_real_amenity_meal_plan_and_sort_filters(): void
    {
        TaxonomyNode::create(['type' => 'accommodation_facility', 'slug' => 'wifi', 'label' => 'WiFi', 'sort_order' => 0, 'meta' => ['booking_facility_id' => 107]]);
        TaxonomyNode::create(['type' => 'room_facility', 'slug' => 'privatno_kupatilo', 'label' => 'Private bathroom', 'sort_order' => 0, 'meta' => ['booking_facility_id' => 38]]);
        TaxonomyNode::create(['type' => 'meal_plan', 'slug' => 'dorucak', 'label' => 'Breakfast', 'sort_order' => 0, 'meta' => ['booking_meal_plan_id' => 1]]);
        TaxonomyNode::create(['type' => 'meal_plan', 'slug' => 'sve_ukljuceno', 'label' => 'All-inclusive', 'sort_order' => 1, 'meta' => ['booking_meal_plan_id' => 4]]);
        TaxonomyNode::create(['type' => 'stay_type', 'slug' => 'kucni_ljubimci', 'label' => 'Pets allowed', 'sort_order' => 0, 'meta' => ['booking_stay_type_id' => 1]]);
        TaxonomyNode::create(['type' => 'popular_activity', 'slug' => 'plazanje', 'label' => 'Beach', 'sort_order' => 0, 'meta' => ['booking_popular_activity_id' => 302]]);
        TaxonomyNode::create(['type' => 'preference_tag', 'slug' => 'porodicna_atmosfera', 'label' => 'Family-friendly', 'sort_order' => 0]);

        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'melieha2', 'label' => 'Mellieħa', 'sort_order' => 0]);
        $session = SearchSession::create([
            'status' => 'in_progress',
            'city_id' => $city->id,
            'date_from' => '2026-09-19',
            'date_to' => '2026-09-27',
            'adults_count' => 1,
            'free_text_answers' => [
                'amenities_yes' => ['wifi', 'privatno_kupatilo', 'dorucak', 'sve_ukljuceno', 'kucni_ljubimci', 'plazanje'],
                'preference_tags' => ['jeftino', 'porodicna_atmosfera'],
            ],
        ]);

        $url = (new SearchSessionQueryCompiler($session))->toBookingUrl();

        $this->assertStringContainsString(rawurlencode('hotelfacility=107'), $url);
        $this->assertStringContainsString(rawurlencode('roomfacility=38'), $url);
        $this->assertStringContainsString(rawurlencode('mealplan=1'), $url);
        $this->assertStringContainsString(rawurlencode('mealplan=4'), $url);
        $this->assertStringContainsString(rawurlencode('stay_type=1'), $url);
        $this->assertStringContainsString(rawurlencode('popular_activities=302'), $url);
        $this->assertStringContainsString('order=price', $url);
        $this->assertStringContainsString('family_friendly_property=1', $url);
    }

    /** Owner's ask, 2026-08-23 ("izabrao sam da je cena manja od 140 evra po noci... za sad
     *  imamo neki budzet i ideju kolko ce da daju za hranu") — see
     *  SearchSessionQueryCompiler::accommodationNightlyPriceCeiling's docblock. Hand-calculated:
     *  meal=10/coffee=2 -> eating-out 27 EUR/adult/day (2.5*10 + 1*2), self-catering 27/3.5 =
     *  7.71 EUR/adult/day, both x7 nights x1 adult. */
    public function test_booking_url_includes_a_price_ceiling_derived_from_total_budget_and_meal_style(): void
    {
        $country = TaxonomyNode::create([
            'type' => 'country', 'slug' => 'ceilingland', 'label' => 'Ceilingland', 'sort_order' => 0,
            'meta' => ['hospitality' => ['avg_restaurant_meal_eur' => 10, 'avg_cafe_coffee_eur' => 2]],
        ]);
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'ceilingtown', 'label' => 'Ceilingtown', 'sort_order' => 0, 'parent_id' => $country->id]);

        $baseSession = [
            'status' => 'in_progress',
            'city_id' => $city->id,
            'date_from' => '2026-09-19',
            // diffInDays(checkin, checkout) + 1 = 7 "days" for this class's own budget math
            // (same convention resolveBudgetContext()/budgetSignal() already use) -- 6 calendar
            // days apart, not 7, to land on exactly 7.
            'date_to' => '2026-09-25',
            'adults_count' => 1,
            'total_budget' => 1000,
        ];

        // jede_napolju: eating-out total = 27 * 7 = 189. (1000 - 189) / 7 = 115.86 -> floor 115.
        $eatingOutSession = SearchSession::create([...$baseSession, 'free_text_answers' => ['meal_style' => 'jede_napolju']]);
        $eatingOutUrl = (new SearchSessionQueryCompiler($eatingOutSession))->toBookingUrl();
        $this->assertStringContainsString(rawurlencode('price=EUR-min-115-1'), $eatingOutUrl);

        // sam_se_snalazim: self-catering total = 189 / 3.5 = 54. (1000 - 54) / 7 = 135.14 -> floor 135.
        $selfCateringSession = SearchSession::create([...$baseSession, 'free_text_answers' => ['meal_style' => 'sam_se_snalazim']]);
        $selfCateringUrl = (new SearchSessionQueryCompiler($selfCateringSession))->toBookingUrl();
        $this->assertStringContainsString(rawurlencode('price=EUR-min-135-1'), $selfCateringUrl);

        // u_smestaju + sve_ukljuceno: fully covered, 0 out-of-pocket. 1000 / 7 = 142.86 -> floor 142.
        $allInclusiveSession = SearchSession::create([
            ...$baseSession,
            'free_text_answers' => ['meal_style' => 'u_smestaju', 'meal_plan_preference' => ['sve_ukljuceno']],
        ]);
        $allInclusiveUrl = (new SearchSessionQueryCompiler($allInclusiveSession))->toBookingUrl();
        $this->assertStringContainsString(rawurlencode('price=EUR-min-142-1'), $allInclusiveUrl);

        // No meal_style answered yet: no price filter at all, not a guess.
        $unansweredSession = SearchSession::create([...$baseSession, 'free_text_answers' => []]);
        $unansweredUrl = (new SearchSessionQueryCompiler($unansweredSession))->toBookingUrl();
        $this->assertStringNotContainsString('price%3DEUR', $unansweredUrl);
    }

    public function test_booking_flights_url_is_null_without_a_destination_or_dates(): void
    {
        $session = SearchSession::create(['status' => 'in_progress']);

        $this->assertNull((new SearchSessionQueryCompiler($session))->toBookingFlightsUrl());
    }

    /** Shape matches a real captured example (owner ran an actual search, Niš -> Malta, 2026-08-
     *  19) -- see toBookingFlightsUrl's docblock for why aid/label from that capture aren't
     *  copied in. Destination resolves to the COUNTRY (toCountryCode/toLocationName) even when
     *  the session has a specific CITY chosen, matching how the real example searched "Malta"
     *  as a whole, not one airport within it. */
    public function test_booking_flights_url_uses_country_level_destination_and_frankfurt_origin(): void
    {
        $country = TaxonomyNode::create(['type' => 'country', 'slug' => 'malta', 'label' => 'Malta', 'sort_order' => 0, 'meta' => ['iso_code' => 'MT']]);
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'melieha', 'label' => 'Mellieha', 'sort_order' => 0, 'parent_id' => $country->id]);

        $session = SearchSession::create([
            'status' => 'in_progress',
            'city_id' => $city->id,
            'date_from' => '2026-09-19',
            'date_to' => '2026-09-26',
            'adults_count' => 2,
            'children_ages' => [11, 9, 1],
        ]);

        $url = (new SearchSessionQueryCompiler($session))->toBookingFlightsUrl();

        $this->assertStringStartsWith('https://flights.booking.com/fly-anywhere/?', $url);
        $this->assertStringContainsString('type=ROUNDTRIP', $url);
        $this->assertStringContainsString('adults=2', $url);
        $this->assertStringContainsString('depart=2026-09-19', $url);
        $this->assertStringContainsString('return=2026-09-26', $url);
        $this->assertStringContainsString('from=FRA.AIRPORT', $url);
        $this->assertStringContainsString('toCountryCode=mt', $url);
        $this->assertStringContainsString('toLocationName='.rawurlencode('Malta'), $url);
        $this->assertStringContainsString('children='.rawurlencode('11,9,1'), $url);
        $this->assertStringNotContainsString('aid=', $url);
        $this->assertStringNotContainsString('label=', $url);
    }

    public function test_booking_flights_url_wraps_with_cj_affiliate_tracking_when_configured(): void
    {
        config(['services.cj.pid' => '101857480', 'services.cj.link_id' => '15734849']);

        $country = TaxonomyNode::create(['type' => 'country', 'slug' => 'malta2', 'label' => 'Malta', 'sort_order' => 0, 'meta' => ['iso_code' => 'MT']]);
        $session = SearchSession::create([
            'status' => 'in_progress',
            'city_id' => $country->id,
            'date_from' => '2026-09-19',
            'date_to' => '2026-09-26',
            'adults_count' => 2,
        ]);

        $url = (new SearchSessionQueryCompiler($session))->toBookingFlightsUrl();

        $this->assertStringStartsWith('https://www.dpbolvw.net/click-101857480-15734849?url=', $url);
        $this->assertStringContainsString(rawurlencode('https://flights.booking.com/fly-anywhere/?'), $url);
    }

    public function test_booking_params_apply_family_friendly_filter_when_porodicna_atmosfera_selected(): void
    {
        $session = SearchSession::create([
            'status' => 'in_progress',
            'free_text_answers' => ['preference_tags' => ['porodicna_atmosfera']],
        ]);

        $params = (new SearchSessionQueryCompiler($session))->toBookingParams();

        $this->assertSame(1, $params['filters']['family_friendly_property']);
    }

    public function test_booking_params_omit_family_friendly_filter_when_not_selected(): void
    {
        $session = SearchSession::create([
            'status' => 'in_progress',
            'free_text_answers' => ['preference_tags' => ['dobra_hrana']],
        ]);

        $params = (new SearchSessionQueryCompiler($session))->toBookingParams();

        $this->assertArrayNotHasKey('family_friendly_property', $params['filters'] ?? []);
    }

    public function test_honest_report_signals_surface_climate_caveat_below_threshold(): void
    {
        Carbon::setTestNow('2026-07-30');

        $termin = TaxonomyNode::create([
            'type' => 'termin_category', 'slug' => 'kasno_kupanje', 'label' => 'test', 'sort_order' => 0,
            'meta' => [
                'window_start' => '09-20', 'default_duration_days' => 5,
                'honest_report_thresholds' => ['sea_temp_c' => ['good' => 22, 'caveat' => 18]],
            ],
        ]);
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'test', 'sort_order' => 0]);
        TaxonomyNodeClimate::create(['taxonomy_node_id' => $city->id, 'month' => 9, 'sea_temp_c' => 17]);

        $session = SearchSession::create(['status' => 'in_progress', 'termin_category' => $termin->slug, 'city_id' => $city->id]);

        $signals = (new SearchSessionQueryCompiler($session))->toHonestReportSignals();

        $this->assertSame('cold', $signals['climate']['by_month'][0]['caveats']['sea_temp_c']);

        Carbon::setTestNow();
    }

    public function test_honest_report_signals_have_no_caveat_when_above_threshold(): void
    {
        Carbon::setTestNow('2026-07-30');

        $termin = TaxonomyNode::create([
            'type' => 'termin_category', 'slug' => 'kasno_kupanje', 'label' => 'test', 'sort_order' => 0,
            'meta' => [
                'window_start' => '09-20', 'default_duration_days' => 5,
                'honest_report_thresholds' => ['sea_temp_c' => ['good' => 22, 'caveat' => 18]],
            ],
        ]);
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'test', 'sort_order' => 0]);
        TaxonomyNodeClimate::create(['taxonomy_node_id' => $city->id, 'month' => 9, 'sea_temp_c' => 25]);

        $session = SearchSession::create(['status' => 'in_progress', 'termin_category' => $termin->slug, 'city_id' => $city->id]);

        $signals = (new SearchSessionQueryCompiler($session))->toHonestReportSignals();

        $this->assertArrayNotHasKey('caveats', $signals['climate']['by_month'][0]);

        Carbon::setTestNow();
    }

    public function test_cost_emphasis_uses_max_not_sum_across_selected_nodes(): void
    {
        $costCategory = TaxonomyNode::create(['type' => 'cost_category', 'slug' => 'hospitality', 'label' => 'test', 'sort_order' => 0]);
        $persona = TaxonomyNode::create(['type' => 'persona', 'slug' => 'gurman', 'label' => 'test', 'sort_order' => 0]);
        $tag = TaxonomyNode::create(['type' => 'preference_tag', 'slug' => 'kvalitet', 'label' => 'test', 'sort_order' => 0]);

        $persona->weightedToward()->attach($costCategory->id, ['relation_type' => 'weighted_toward', 'meta' => json_encode(['weight' => 3])]);
        $tag->weightedToward()->attach($costCategory->id, ['relation_type' => 'weighted_toward', 'meta' => json_encode(['weight' => 2])]);

        $session = SearchSession::create([
            'status' => 'in_progress',
            'persona_id' => $persona->id,
            'free_text_answers' => ['preference_tags' => ['kvalitet']],
        ]);

        $signals = (new SearchSessionQueryCompiler($session))->toHonestReportSignals();

        $this->assertSame(3, $signals['cost_emphasis']['hospitality']);
    }

    public function test_preference_tags_merge_explicit_and_implied(): void
    {
        $session = SearchSession::create([
            'status' => 'in_progress',
            'free_text_answers' => [
                'preference_tags' => ['pivo'],
                'implied_preference_tags' => ['dobra_hrana'],
            ],
        ]);

        $signals = (new SearchSessionQueryCompiler($session))->toHonestReportSignals();

        $this->assertEqualsCanonicalizing(['pivo', 'dobra_hrana'], $signals['preference_tags']);
    }

    public function test_budget_signal_reflects_country_fit_via_city_parent(): void
    {
        Carbon::setTestNow('2026-07-30');

        $country = TaxonomyNode::create([
            'type' => 'country', 'slug' => 'testland', 'label' => 'test', 'sort_order' => 0,
            'meta' => ['hospitality' => ['avg_restaurant_meal_eur' => 10, 'avg_cafe_coffee_eur' => 2]],
        ]);
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'test', 'parent_id' => $country->id, 'sort_order' => 0]);
        TaxonomyNode::create(['type' => 'termin_category', 'slug' => 'kasno_kupanje', 'label' => 'test', 'sort_order' => 0, 'meta' => ['window_start' => '09-20', 'default_duration_days' => 5]]);

        $session = SearchSession::create([
            'status' => 'in_progress', 'termin_category' => 'kasno_kupanje', 'city_id' => $city->id,
            'adults_count' => 2, 'total_budget' => 1000,
        ]);

        $signals = (new SearchSessionQueryCompiler($session))->toHonestReportSignals();

        $this->assertSame(1000.0, $signals['budget']['total_budget_eur']);
        $this->assertSame('eating_out', $signals['budget']['fit']);

        Carbon::setTestNow();
    }

    public function test_includes_meals_price_row_zeroes_out_the_food_estimate(): void
    {
        // Owner's catch, 2026-08-05: Egyptian Red Sea resorts (Hurghada/Sharm El Sheikh) are
        // almost always all-inclusive — the campaign price already bundles food, so the
        // separate food estimate must be zeroed, not double-counted on top.
        Carbon::setTestNow('2026-08-05');

        $country = TaxonomyNode::create([
            'type' => 'country', 'slug' => 'testland', 'label' => 'test', 'sort_order' => 0,
            'meta' => ['hospitality' => ['avg_restaurant_meal_eur' => 10, 'avg_cafe_coffee_eur' => 2]],
        ]);
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'test', 'parent_id' => $country->id, 'sort_order' => 0]);
        TaxonomyNode::create(['type' => 'termin_category', 'slug' => 'kasno_kupanje', 'label' => 'test', 'sort_order' => 0, 'meta' => ['window_start' => '09-20', 'default_duration_days' => 5]]);

        $campaign = \App\Models\WizardCampaign::create(['key' => 'testcamp', 'label' => 'test', 'is_active' => true, 'sort_order' => 0]);
        \App\Models\WizardCampaignDestinationPrice::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $city->id,
            'price_per_person_eur' => 30, 'includes_meals' => true,
        ]);

        $session = SearchSession::create([
            'status' => 'in_progress', 'wizard_campaign_id' => $campaign->id, 'termin_category' => 'kasno_kupanje',
            'city_id' => $city->id, 'adults_count' => 2, 'total_budget' => 400,
        ]);

        $signals = (new SearchSessionQueryCompiler($session))->toHonestReportSignals();

        $this->assertSame(0.0, $signals['budget']['estimate']['eating_out_total_eur']);
        $this->assertSame(0.0, $signals['budget']['estimate']['self_catering_total_eur']);
        // accommodation alone: 30 * 2 people * 5 nights (window_start 09-20 + 5 duration days —
        // checkin Sep 20, checkout Sep 25, 5 nights slept, no night charged for checkout day
        // itself; owner's catch 2026-08-12, see WizardCampaignDestinationPrice) = 300, well
        // within the 400 budget once food is correctly zeroed instead of added on top.
        $this->assertSame(300.0, $signals['budget']['accommodation_total_eur']);
        $this->assertSame('eating_out', $signals['budget']['fit']);

        Carbon::setTestNow();
    }

    public function test_budget_signal_absent_when_total_budget_not_answered(): void
    {
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'test', 'sort_order' => 0]);
        $session = SearchSession::create(['status' => 'in_progress', 'city_id' => $city->id, 'adults_count' => 2]);

        $signals = (new SearchSessionQueryCompiler($session))->toHonestReportSignals();

        $this->assertArrayNotHasKey('budget', $signals);
    }

    /**
     * Regression test for the bug caught 2026-07-30 via the debug panel: picking a country but
     * "svejedno koji grad" (skipping the specific city) silently dropped location, climate, AND
     * budget — all three were reading session->city directly instead of falling back to
     * country_region. See SearchSessionQueryCompiler::destinationNode().
     */
    public function test_country_only_session_still_gets_location_climate_and_budget(): void
    {
        Carbon::setTestNow('2026-07-30');

        $location = Location::create(['booking_dest_id' => 'test_malta', 'dest_type' => 'country', 'name' => 'Malta', 'source' => 'manual_test']);
        $country = TaxonomyNode::create([
            'type' => 'country', 'slug' => 'malta', 'label' => 'test', 'sort_order' => 0,
            'booking_location_id' => $location->id,
            'meta' => ['hospitality' => ['avg_restaurant_meal_eur' => 10, 'avg_cafe_coffee_eur' => 2]],
        ]);
        TaxonomyNodeClimate::create(['taxonomy_node_id' => $country->id, 'month' => 9, 'sea_temp_c' => 24]);
        $termin = TaxonomyNode::create([
            'type' => 'termin_category', 'slug' => 'kasno_kupanje', 'label' => 'test', 'sort_order' => 0,
            'meta' => ['window_start' => '09-20', 'default_duration_days' => 5, 'honest_report_thresholds' => ['sea_temp_c' => ['good' => 22, 'caveat' => 18]]],
        ]);

        // No city_id at all — country_region_id only, exactly the "svejedno koji grad" case.
        $session = SearchSession::create([
            'status' => 'in_progress', 'termin_category' => $termin->slug,
            'country_region_id' => $country->id, 'adults_count' => 1, 'total_budget' => 1000,
        ]);

        $params = (new SearchSessionQueryCompiler($session))->toBookingParams();
        $signals = (new SearchSessionQueryCompiler($session))->toHonestReportSignals();

        $this->assertSame('test_malta', $params['location']);
        $this->assertSame(24.0, $signals['climate']['by_month'][0]['sea_temp_c']);
        $this->assertSame('eating_out', $signals['budget']['fit']);

        Carbon::setTestNow();
    }

    public function test_big_yes_amenities_route_to_their_real_booking_filters(): void
    {
        TaxonomyNode::create([
            'type' => 'tip_smestaja', 'slug' => 'vila', 'label' => 'test', 'sort_order' => 0,
            'meta' => ['booking_accommodation_type_ids' => [213]],
        ]);
        TaxonomyNode::create([
            'type' => 'accommodation_facility', 'slug' => 'bazen', 'label' => 'test', 'sort_order' => 0,
            'meta' => ['booking_facility_id' => 433],
        ]);
        TaxonomyNode::create([
            'type' => 'room_facility', 'slug' => 'klima', 'label' => 'test', 'sort_order' => 0,
            'meta' => ['booking_facility_id' => 11],
        ]);
        TaxonomyNode::create([
            'type' => 'meal_plan', 'slug' => 'dorucak', 'label' => 'test', 'sort_order' => 0,
            'meta' => ['booking_meal_plan_id' => 1],
        ]);

        $session = SearchSession::create([
            'status' => 'in_progress',
            'free_text_answers' => ['amenities_yes' => ['vila', 'bazen', 'klima', 'dorucak']],
        ]);

        $params = (new SearchSessionQueryCompiler($session))->toBookingParams();

        $this->assertSame([213], $params['filters']['accommodation_types']);
        $this->assertSame([433], $params['filters']['accommodation_facilities']);
        $this->assertSame([11], $params['filters']['room_facilities']);
        $this->assertSame([1], $params['filters']['meal_plan']);
    }

    public function test_big_no_amenities_surface_only_as_a_honest_report_signal(): void
    {
        $session = SearchSession::create([
            'status' => 'in_progress',
            'free_text_answers' => ['amenities_no' => ['bazen', 'zivahna_nocna_zabava']],
        ]);

        $params = (new SearchSessionQueryCompiler($session))->toBookingParams();
        $signals = (new SearchSessionQueryCompiler($session))->toHonestReportSignals();

        $this->assertArrayNotHasKey('filters', $params);
        $this->assertSame(['bazen', 'zivahna_nocna_zabava'], $signals['avoid_amenities']);
    }

    public function test_avoid_notes_come_from_smestaj_avoid_split_by_line_separately_from_avoid_amenities(): void
    {
        $session = SearchSession::create([
            'status' => 'in_progress',
            'free_text_answers' => ['amenities_no' => ['bazen'], 'smestaj_avoid' => "Crowd\nLoud"],
        ]);

        $signals = (new SearchSessionQueryCompiler($session))->toHonestReportSignals();

        $this->assertSame(['bazen'], $signals['avoid_amenities']);
        $this->assertSame(['Crowd', 'Loud'], $signals['avoid_notes']);
    }

    public function test_amenities_absent_when_session_has_no_picks(): void
    {
        $session = SearchSession::create(['status' => 'in_progress']);

        $params = (new SearchSessionQueryCompiler($session))->toBookingParams();
        $signals = (new SearchSessionQueryCompiler($session))->toHonestReportSignals();

        $this->assertArrayNotHasKey('filters', $params);
        $this->assertArrayNotHasKey('avoid_amenities', $signals);
    }
}
