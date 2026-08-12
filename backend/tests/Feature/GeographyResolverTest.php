<?php

namespace Tests\Feature;

use App\GraphQL\Resolvers\GeographyResolver;
use App\Models\SearchSession;
use App\Models\TaxonomyNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers GeographyResolver::suggested — the excludes/implies filtering + preference-tag match
 * scoring that every taxonomy-typed wizard question routes through (see wizard_architecture,
 * "a real bug was caught and fixed where preference_tags/budget_tier were still using the old
 * static options field"). Guards against that class of regression coming back.
 */
class GeographyResolverTest extends TestCase
{
    use RefreshDatabase;

    private function node(string $type, string $slug, ?array $meta = null): TaxonomyNode
    {
        return TaxonomyNode::create([
            'type' => $type,
            'slug' => $slug,
            'label' => $slug,
            'sort_order' => 0,
            'meta' => $meta,
        ]);
    }

    public function test_excludes_removes_option_from_offered_list(): void
    {
        $cityBreak = $this->node('trip_type', 'city_break');
        $letovanje = $this->node('termin_category', 'letovanje');
        $vikend = $this->node('termin_category', 'vikend_break');

        $cityBreak->excludes()->attach($letovanje->id, ['relation_type' => 'excludes']);

        $session = SearchSession::create(['status' => 'in_progress', 'trip_type_id' => $cityBreak->id]);

        $results = (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'termin_category']);

        $this->assertFalse($results->pluck('id')->contains($letovanje->id));
        $this->assertTrue($results->pluck('id')->contains($vikend->id));
    }

    public function test_parent_ids_narrows_to_any_of_several_selected_countries(): void
    {
        // Owner's ask, 2026-08-12: Country/region became multi-select — the City step must
        // gather candidates from ANY of the selected countries, not just one.
        $grcka = $this->node('country', 'grcka');
        $italija = $this->node('country', 'italija');
        $spanija = $this->node('country', 'spanija');
        $rodos = $this->node('city', 'rodos');
        $rodos->update(['parent_id' => $grcka->id]);
        $taormina = $this->node('city', 'taormina');
        $taormina->update(['parent_id' => $italija->id]);
        $tenerife = $this->node('city', 'tenerife');
        $tenerife->update(['parent_id' => $spanija->id]);

        $session = SearchSession::create(['status' => 'in_progress']);

        $results = (new GeographyResolver)->suggested(null, [
            'sessionId' => $session->id, 'type' => 'city', 'parentIds' => [$grcka->id, $italija->id],
        ]);

        $this->assertTrue($results->pluck('id')->contains($rodos->id));
        $this->assertTrue($results->pluck('id')->contains($taormina->id));
        $this->assertFalse($results->pluck('id')->contains($tenerife->id));
    }

    public function test_implies_keeps_the_implied_option_in_the_list_but_flags_it(): void
    {
        // Owner's call, 2026-08-04: an implied option ("obvious" consequence) stays visible,
        // marked so the frontend shows it selected-and-locked — it used to be hidden outright,
        // which read as the option silently vanishing rather than "already assumed for you".
        $gurman = $this->node('persona', 'gurman');
        $dobraHrana = $this->node('preference_tag', 'dobra_hrana');
        $pivo = $this->node('preference_tag', 'pivo');

        $gurman->implies()->attach($dobraHrana->id, ['relation_type' => 'implies']);

        $session = SearchSession::create(['status' => 'in_progress', 'persona_id' => $gurman->id]);

        $results = (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'preference_tag']);

        $implied = $results->firstWhere('id', $dobraHrana->id);
        $this->assertNotNull($implied);
        $this->assertTrue($implied->implied);

        $notImplied = $results->firstWhere('id', $pivo->id);
        $this->assertNotNull($notImplied);
        $this->assertFalse($notImplied->implied);
    }

    public function test_preference_tag_overlap_ranks_matching_nodes_higher(): void
    {
        $italija = $this->node('country', 'italija', ['food' => ['dobra_hrana'], 'drinks' => ['vino']]);
        $spanija = $this->node('country', 'spanija', ['food' => ['dobra_hrana']]);
        $belgija = $this->node('country', 'belgija', ['drinks' => ['pivo']]);

        $session = SearchSession::create([
            'status' => 'in_progress',
            'free_text_answers' => ['preference_tags' => ['dobra_hrana', 'vino']],
        ]);

        $results = (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'country']);

        $this->assertSame($italija->id, $results->first()->id);
        $this->assertEqualsCanonicalizing(['dobra_hrana', 'vino'], $results->first()->matched_tags);
        $this->assertGreaterThan(
            $results->firstWhere('id', $spanija->id)->match_score,
            $results->firstWhere('id', $italija->id)->match_score
        );

        // Owner's call, 2026-08-11: zero-match candidates are hidden entirely once a preference
        // has been stated, not just ranked lower — see GeographyResolver::suggested docblock.
        $this->assertNull($results->firstWhere('id', $belgija->id));
    }

    public function test_zero_match_filter_falls_back_to_full_list_when_nothing_matches_at_all(): void
    {
        // If a region's atmosphere/drinks/food tags simply aren't seeded yet, hiding every
        // candidate would produce a blank screen — must fall back to showing everything.
        $italija = $this->node('country', 'italija');
        $spanija = $this->node('country', 'spanija');

        $session = SearchSession::create([
            'status' => 'in_progress',
            'free_text_answers' => ['preference_tags' => ['dobra_hrana']],
        ]);

        $results = (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'country']);

        $this->assertCount(2, $results);
    }

    public function test_implied_preference_tags_count_toward_match_score_too(): void
    {
        // GeographyResolver reads BOTH preference_tags and implied_preference_tags (see
        // SearchSessionResolver's separate-key comment) — a country matched only via an
        // implied tag must still rank above one with no match at all.
        $italija = $this->node('country', 'italija', ['food' => ['dobra_hrana']]);
        $belgija = $this->node('country', 'belgija', ['drinks' => ['pivo']]);

        $session = SearchSession::create([
            'status' => 'in_progress',
            'free_text_answers' => ['implied_preference_tags' => ['dobra_hrana']],
        ]);

        $results = (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'country']);

        $this->assertSame($italija->id, $results->first()->id);
    }

    public function test_cultural_availability_excludes_countries_below_requested_tier(): void
    {
        $liberal = $this->node('country', 'liberal');
        $liberal->culturalAvailability()->create(['category' => 'alcohol', 'tier' => 1, 'label' => 'test']);
        $restricted = $this->node('country', 'restricted');
        $restricted->culturalAvailability()->create(['category' => 'alcohol', 'tier' => 4, 'label' => 'test']);

        $this->node('preference_tag', 'zeli_alkohol_slobodno', ['cultural_category' => 'alcohol', 'max_tier' => 2]);

        $session = SearchSession::create([
            'status' => 'in_progress',
            'free_text_answers' => ['preference_tags' => ['zeli_alkohol_slobodno']],
        ]);

        $results = (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'country']);

        $this->assertTrue($results->pluck('id')->contains($liberal->id));
        $this->assertFalse($results->pluck('id')->contains($restricted->id));
    }

    public function test_cultural_availability_keeps_countries_with_no_data_for_that_category(): void
    {
        $noData = $this->node('country', 'nodata');
        $this->node('preference_tag', 'zeli_alkohol_slobodno', ['cultural_category' => 'alcohol', 'max_tier' => 2]);

        $session = SearchSession::create([
            'status' => 'in_progress',
            'free_text_answers' => ['preference_tags' => ['zeli_alkohol_slobodno']],
        ]);

        $results = (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'country']);

        $this->assertTrue($results->pluck('id')->contains($noData->id));
    }

    public function test_budget_narrows_countries_and_flags_no_caveat_when_something_fits(): void
    {
        $cheap = $this->node('country', 'cheap', ['hospitality' => ['avg_restaurant_meal_eur' => 5, 'avg_cafe_coffee_eur' => 1]]);
        // even self-catering (÷3.5) stays over the 1000 budget: adult_daily=470, eating_out=6580, self_catering≈1880
        $expensive = $this->node('country', 'expensive', ['hospitality' => ['avg_restaurant_meal_eur' => 150, 'avg_cafe_coffee_eur' => 20]]);
        $termin = $this->node('termin_category', 'kasno_kupanje', ['default_duration_days' => 7]);

        $session = SearchSession::create([
            'status' => 'in_progress', 'termin_category' => $termin->slug,
            'adults_count' => 2, 'total_budget' => 1000,
        ]);

        $results = (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'country']);

        $this->assertTrue($results->pluck('id')->contains($cheap->id));
        $this->assertFalse($results->pluck('id')->contains($expensive->id));
        $this->assertFalse($results->firstWhere('id', $cheap->id)->budget_caveat);
    }

    public function test_budget_falls_back_to_closest_with_caveat_when_nothing_fits(): void
    {
        $a = $this->node('country', 'a', ['hospitality' => ['avg_restaurant_meal_eur' => 60, 'avg_cafe_coffee_eur' => 10]]);
        $b = $this->node('country', 'b', ['hospitality' => ['avg_restaurant_meal_eur' => 65, 'avg_cafe_coffee_eur' => 11]]);
        $termin = $this->node('termin_category', 'kasno_kupanje', ['default_duration_days' => 7]);

        $session = SearchSession::create([
            'status' => 'in_progress', 'termin_category' => $termin->slug,
            'adults_count' => 2, 'total_budget' => 10,
        ]);

        $results = (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'country']);

        $this->assertCount(2, $results->whereIn('id', [$a->id, $b->id]));
        $this->assertTrue($results->firstWhere('id', $a->id)->budget_caveat);
    }

    public function test_budget_is_skipped_entirely_when_total_budget_not_answered(): void
    {
        // No total_budget on the session — must behave exactly like before this feature existed.
        $anyCountry = $this->node('country', 'anycountry', ['hospitality' => ['avg_restaurant_meal_eur' => 60, 'avg_cafe_coffee_eur' => 10]]);

        $session = SearchSession::create(['status' => 'in_progress', 'adults_count' => 2]);

        $results = (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'country']);

        $this->assertTrue($results->pluck('id')->contains($anyCountry->id));
    }

    public function test_price_rank_is_relative_to_the_current_candidate_set(): void
    {
        $cheap = $this->node('country', 'cheap');
        $pricey = $this->node('country', 'pricey');
        $cheapCity = $this->node('city', 'cheap_city');
        $cheapCity->update(['parent_id' => $cheap->id]);
        $priceyCity = $this->node('city', 'pricey_city');
        $priceyCity->update(['parent_id' => $pricey->id]);

        $campaign = \App\Models\WizardCampaign::create(['key' => 'test-campaign', 'label' => 'Test']);
        \App\Models\WizardCampaignDestinationPrice::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $cheapCity->id, 'price_per_person_eur' => 20,
        ]);
        \App\Models\WizardCampaignDestinationPrice::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $priceyCity->id, 'price_per_person_eur' => 200,
        ]);

        $session = SearchSession::create([
            'status' => 'in_progress', 'adults_count' => 2, 'wizard_campaign_id' => $campaign->id,
            'date_from' => '2026-09-01', 'date_to' => '2026-09-08',
        ]);

        $results = (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'country']);

        $this->assertSame(1, $results->firstWhere('id', $cheap->id)->price_rank);
        $this->assertSame(5, $results->firstWhere('id', $pricey->id)->price_rank);
    }

    public function test_price_rank_is_null_with_fewer_than_two_priced_candidates(): void
    {
        $onlyOne = $this->node('country', 'onlyone');
        $city = $this->node('city', 'onlyone_city');
        $city->update(['parent_id' => $onlyOne->id]);

        $campaign = \App\Models\WizardCampaign::create(['key' => 'test-campaign-2', 'label' => 'Test 2']);
        \App\Models\WizardCampaignDestinationPrice::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $city->id, 'price_per_person_eur' => 20,
        ]);

        $session = SearchSession::create([
            'status' => 'in_progress', 'adults_count' => 2, 'wizard_campaign_id' => $campaign->id,
            'date_from' => '2026-09-01', 'date_to' => '2026-09-08',
        ]);

        $results = (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'country']);

        $this->assertNull($results->firstWhere('id', $onlyOne->id)->price_rank);
    }

    public function test_falls_back_to_price_ascending_when_nothing_has_a_real_match_score(): void
    {
        // Owner's call, 2026-08-11: "cena je uvek parametar, jer svako oce da ustedi" — when
        // every candidate ties at match_score 0 (no preference selected, or the zero-match
        // fallback kept everything), sort by price instead of leaving order arbitrary.
        $cheap = $this->node('country', 'cheap');
        $pricey = $this->node('country', 'pricey');
        $cheapCity = $this->node('city', 'cheap_city');
        $cheapCity->update(['parent_id' => $cheap->id]);
        $priceyCity = $this->node('city', 'pricey_city');
        $priceyCity->update(['parent_id' => $pricey->id]);

        $campaign = \App\Models\WizardCampaign::create(['key' => 'test-campaign-3', 'label' => 'Test 3']);
        \App\Models\WizardCampaignDestinationPrice::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $cheapCity->id, 'price_per_person_eur' => 20,
        ]);
        \App\Models\WizardCampaignDestinationPrice::create([
            'wizard_campaign_id' => $campaign->id, 'taxonomy_node_id' => $priceyCity->id, 'price_per_person_eur' => 200,
        ]);

        $session = SearchSession::create([
            'status' => 'in_progress', 'adults_count' => 2, 'wizard_campaign_id' => $campaign->id,
            'date_from' => '2026-09-01', 'date_to' => '2026-09-08',
        ]);

        $results = (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'country']);

        $this->assertSame($cheap->id, $results->first()->id);
        $this->assertSame($pricey->id, $results->last()->id);
    }
}
