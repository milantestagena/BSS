<?php

namespace Tests\Unit;

use App\Models\TaxonomyNode;
use App\Services\BudgetEstimationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers BudgetEstimationEngine — see wizard_architecture memory, 2026-07-30. Formula
 * verified against the owner's own worked example (2 adults + 2 children, 2000 EUR, 7 days).
 */
class BudgetEstimationEngineTest extends TestCase
{
    use RefreshDatabase;

    private function countryWithPrices(float $meal, float $coffee, string $slug = 'testland'): TaxonomyNode
    {
        return TaxonomyNode::create([
            'type' => 'country', 'slug' => $slug, 'label' => $slug, 'sort_order' => 0,
            'meta' => ['hospitality' => ['avg_restaurant_meal_eur' => $meal, 'avg_cafe_coffee_eur' => $coffee]],
        ]);
    }

    public function test_returns_null_when_country_has_no_hospitality_meta(): void
    {
        $country = TaxonomyNode::create(['type' => 'country', 'slug' => 'noinfo', 'label' => 'test', 'sort_order' => 0]);

        $this->assertNull((new BudgetEstimationEngine)->estimate($country, 2, 2, 7));
    }

    public function test_estimate_matches_hand_calculation(): void
    {
        $country = $this->countryWithPrices(meal: 10, coffee: 2);

        // adult daily = 2.5*10 + 1*2 = 27; child daily = 27*0.5 + 1*(2*2) = 17.5
        // 7 days, 2 adults + 2 children => (2*27 + 2*17.5) * 7 = 89 * 7 = 623
        $estimate = (new BudgetEstimationEngine)->estimate($country, adults: 2, children: 2, days: 7);

        $this->assertSame(623.0, $estimate['eating_out_total_eur']);
        $this->assertEqualsWithDelta(623 / 3.5, $estimate['self_catering_total_eur'], 0.01);
    }

    public function test_fit_for_returns_eating_out_when_budget_covers_it(): void
    {
        $country = $this->countryWithPrices(meal: 10, coffee: 2); // eating_out total = 728

        $this->assertSame('eating_out', (new BudgetEstimationEngine)->fitFor($country, 1000, 2, 2, 7));
    }

    public function test_fit_for_returns_self_catering_when_budget_only_covers_that(): void
    {
        $country = $this->countryWithPrices(meal: 10, coffee: 2); // eating_out 728, self_catering ~208

        $this->assertSame('self_catering', (new BudgetEstimationEngine)->fitFor($country, 300, 2, 2, 7));
    }

    public function test_fit_for_returns_insufficient_when_budget_covers_neither(): void
    {
        $country = $this->countryWithPrices(meal: 10, coffee: 2);

        $this->assertSame('insufficient', (new BudgetEstimationEngine)->fitFor($country, 50, 2, 2, 7));
    }

    public function test_narrow_candidates_keeps_only_fitting_countries(): void
    {
        $cheap = $this->countryWithPrices(meal: 5, coffee: 1, slug: 'cheap'); // eating_out 364
        $expensive = $this->countryWithPrices(meal: 60, coffee: 10, slug: 'expensive'); // self_catering ~1220, still over budget

        $result = (new BudgetEstimationEngine)->narrowCandidates(collect([$cheap, $expensive]), 1000, 2, 2, 7);

        $this->assertCount(1, $result);
        $this->assertSame('cheap', $result->first()['country']->slug);
        $this->assertFalse($result->first()['caveat']);
    }

    public function test_narrow_candidates_falls_back_to_two_closest_when_none_fit(): void
    {
        $a = $this->countryWithPrices(meal: 20, coffee: 3, slug: 'a'); // eating_out 1288
        $b = $this->countryWithPrices(meal: 25, coffee: 4, slug: 'b'); // eating_out 1624
        $c = $this->countryWithPrices(meal: 30, coffee: 5, slug: 'c'); // eating_out 2100 (furthest)

        $result = (new BudgetEstimationEngine)->narrowCandidates(collect([$a, $b, $c]), 50, 2, 2, 7);

        $this->assertCount(2, $result);
        $this->assertTrue($result->every(fn (array $row) => $row['caveat'] === true));
        $slugs = $result->pluck('country.slug')->all();
        $this->assertEqualsCanonicalizing(['a', 'b'], $slugs);
    }

    public function test_fit_for_subtracts_accommodation_total_before_comparing(): void
    {
        // Owner's own live example, 2026-08-05: total_budget=2200, accommodation alone=1500
        // (5 people * 30 EUR/person * 10 nights), eating_out for the trip ~838.5. Disposable
        // budget after accommodation = 700, which is below eating_out but above self_catering.
        $country = $this->countryWithPrices(meal: 12, coffee: 2.5); // matches roughly egipat's tonight
        $engine = new BudgetEstimationEngine;

        $this->assertSame('eating_out', $engine->fitFor($country, 2200, 3, 1, 5, accommodationTotal: 0.0));
        $this->assertSame('insufficient', $engine->fitFor($country, 1200, 5, 0, 10, accommodationTotal: 1500.0));
    }

    public function test_fit_for_is_insufficient_when_accommodation_alone_exceeds_budget(): void
    {
        $country = $this->countryWithPrices(meal: 10, coffee: 2);

        $this->assertSame('insufficient', (new BudgetEstimationEngine)->fitFor($country, 500, 2, 0, 5, accommodationTotal: 600.0));
    }

    public function test_narrow_candidates_accepts_a_per_country_accommodation_resolver(): void
    {
        $cheapStay = $this->countryWithPrices(meal: 10, coffee: 2, slug: 'cheapstay'); // eating_out 364 for 2/0/7... see below
        $priceyStay = $this->countryWithPrices(meal: 10, coffee: 2, slug: 'priceystay');

        // Same food cost for both — only accommodation differs, driving the outcome.
        $result = (new BudgetEstimationEngine)->narrowCandidates(
            collect([$cheapStay, $priceyStay]),
            1000, 2, 0, 7,
            fn (TaxonomyNode $country) => $country->slug === 'priceystay' ? 900.0 : 100.0
        );

        $this->assertCount(1, $result);
        $this->assertSame('cheapstay', $result->first()['country']->slug);
    }

    public function test_narrow_candidates_ignores_countries_with_no_cost_data(): void
    {
        $withData = $this->countryWithPrices(meal: 10, coffee: 2, slug: 'withdata');
        $withoutData = TaxonomyNode::create(['type' => 'country', 'slug' => 'nodata', 'label' => 'test', 'sort_order' => 0]);

        $result = (new BudgetEstimationEngine)->narrowCandidates(collect([$withData, $withoutData]), 1000, 2, 2, 7);

        $this->assertCount(1, $result);
        $this->assertSame('withdata', $result->first()['country']->slug);
    }

    public function test_meal_plan_slug_with_an_explicit_coefficient_of_one_costs_exactly_the_eating_out_total(): void
    {
        // Owner's own framing, 2026-08-13: "ono bi uvek bilo *2.5, kad bi koeficijent bio 1" —
        // with meal_plan_coefficient explicitly set to 1.0, any meal_plan_preference must total
        // to the SAME eating_out_total_eur as no preference at all, regardless of which meals
        // are covered — the split between "pay hotel" and "pay restaurant" changes, the total
        // doesn't. Verifies the underlying math, independent of whatever the DEFAULT happens to
        // be (see the next test for that).
        $country = $this->countryWithPrices(meal: 10, coffee: 2); // eating_out total = 623 (2 adults, 2 children, 7 days — see test_estimate_matches_hand_calculation)
        $country->update(['meta' => [...$country->meta, 'meal_plan_coefficient' => 1.0]]);
        $engine = new BudgetEstimationEngine;

        $this->assertSame('dorucak', $engine->fitFor($country, 623, 2, 2, 7, mealPlanSlugs: ['dorucak']));
        $this->assertSame('insufficient', $engine->fitFor($country, 622, 2, 2, 7, mealPlanSlugs: ['dorucak']));
        $this->assertSame('sve_ukljuceno', $engine->fitFor($country, 623, 2, 2, 7, mealPlanSlugs: ['sve_ukljuceno']));
        $this->assertSame('insufficient', $engine->fitFor($country, 622, 2, 2, 7, mealPlanSlugs: ['sve_ukljuceno']));
    }

    public function test_meal_plan_slug_with_no_coefficient_set_uses_the_0_8_default(): void
    {
        // Owner's call, 2026-08-13, after cross-checking MEAL_PLAN_COVERAGE_RATIOS against an
        // independent real-world board-supplement estimate: 0.7 lined up well across every
        // tier, 0.8 picked as the shipped default to be a bit more conservative ("da budemo
        // sigurni... najjeftiniji retko kad nude all inclusive").
        $country = $this->countryWithPrices(meal: 10, coffee: 2); // eating_out total = 623
        $engine = new BudgetEstimationEngine;

        // dorucak: coveredFraction 0.12 -> 623 * (1 + 0.12*(0.8-1)) = 608.048
        $this->assertSame('dorucak', $engine->fitFor($country, 609, 2, 2, 7, mealPlanSlugs: ['dorucak']));
        $this->assertSame('insufficient', $engine->fitFor($country, 608, 2, 2, 7, mealPlanSlugs: ['dorucak']));

        // sve_ukljuceno: coveredFraction 1.0 -> 623 * 0.8 = 498.4
        $this->assertSame('sve_ukljuceno', $engine->fitFor($country, 499, 2, 2, 7, mealPlanSlugs: ['sve_ukljuceno']));
        $this->assertSame('insufficient', $engine->fitFor($country, 498, 2, 2, 7, mealPlanSlugs: ['sve_ukljuceno']));
    }

    /** Owner's ask, 2026-08-23 — see SearchSessionQueryCompiler::accommodationNightlyPriceCeiling.
     *  Distinct from mealPlanTotalFor above: this is only the LEFTOVER out-of-pocket slice for
     *  meals the hotel plan doesn't cover, not the full combined food spend. */
    public function test_out_of_pocket_meal_total_is_zero_for_all_inclusive(): void
    {
        $country = $this->countryWithPrices(meal: 10, coffee: 2);
        $engine = new BudgetEstimationEngine;

        $this->assertSame(0.0, $engine->outOfPocketMealTotal($country, 'sve_ukljuceno', 623.0));
    }

    public function test_out_of_pocket_meal_total_is_most_of_the_eating_out_estimate_for_breakfast_only(): void
    {
        $country = $this->countryWithPrices(meal: 10, coffee: 2);
        $engine = new BudgetEstimationEngine;

        // dorucak covers 0.3 of 2.5 meal-units -> 88% still out of pocket.
        $this->assertEqualsWithDelta(623.0 * 0.88, $engine->outOfPocketMealTotal($country, 'dorucak', 623.0), 0.01);
    }

    public function test_out_of_pocket_meal_total_is_a_small_slice_for_full_board(): void
    {
        $country = $this->countryWithPrices(meal: 10, coffee: 2);
        $engine = new BudgetEstimationEngine;

        // pun_pansion covers 1.95 of 2.5 meal-units -> 22% still out of pocket.
        $this->assertEqualsWithDelta(623.0 * 0.22, $engine->outOfPocketMealTotal($country, 'pun_pansion', 623.0), 0.01);
    }

    public function test_out_of_pocket_meal_total_treats_an_unrecognized_slug_as_fully_uncovered(): void
    {
        $country = $this->countryWithPrices(meal: 10, coffee: 2);
        $engine = new BudgetEstimationEngine;

        $this->assertSame(623.0, $engine->outOfPocketMealTotal($country, 'not_a_real_slug', 623.0));
    }

    public function test_all_inclusive_costs_more_than_eating_out_when_coefficient_above_one(): void
    {
        $country = $this->countryWithPrices(meal: 10, coffee: 2); // eating_out total = 623
        $country->update(['meta' => [...$country->meta, 'meal_plan_coefficient' => 1.5]]);
        $engine = new BudgetEstimationEngine;

        // sve_ukljuceno covers the FULL 2.5 ratio, so its total is exactly eatingOutTotal * 1.5 = 934.5.
        $this->assertSame('sve_ukljuceno', $engine->fitFor($country, 935, 2, 2, 7, mealPlanSlugs: ['sve_ukljuceno']));
        $this->assertSame('insufficient', $engine->fitFor($country, 934, 2, 2, 7, mealPlanSlugs: ['sve_ukljuceno']));
    }

    public function test_a_lighter_meal_plan_costs_less_than_all_inclusive_under_the_same_coefficient(): void
    {
        // Owner's real bug report: a 500€/8-day all-inclusive session was passing Greece at a
        // price it could never really buy all-inclusive at. Breakfast-only should sit well
        // below all-inclusive for the same destination/coefficient.
        $country = $this->countryWithPrices(meal: 10, coffee: 2);
        $country->update(['meta' => [...$country->meta, 'meal_plan_coefficient' => 1.5]]);
        $engine = new BudgetEstimationEngine;

        // eating_out total 623; dorucak covers 0.3/2.5 of it -> 623 * (1 + 0.12*0.5) = 660.38
        $this->assertSame('dorucak', $engine->fitFor($country, 661, 2, 2, 7, mealPlanSlugs: ['dorucak']));
        $this->assertSame('insufficient', $engine->fitFor($country, 660, 2, 2, 7, mealPlanSlugs: ['dorucak']));
    }

    public function test_meal_style_jede_napolju_only_ever_tries_eating_out(): void
    {
        // Real bug caught live, 2026-08-14: a session that explicitly said "I eat at
        // restaurants" was still shown countries captioned "Fits if you cook for yourself" —
        // the plain no-mealStyle fallback tried self_catering as a second attempt regardless of
        // what was actually said. With mealStyle='jede_napolju', it must NEVER fall back to
        // self_catering, even though the budget would technically cover it.
        $country = $this->countryWithPrices(meal: 10, coffee: 2); // eating_out 623, self_catering 178
        $engine = new BudgetEstimationEngine;

        $this->assertSame('eating_out', $engine->fitFor($country, 623, 2, 2, 7, mealStyle: 'jede_napolju'));
        // Budget only covers self_catering (200 < 623 but > 178) — must be 'insufficient', NOT
        // silently fall back to self_catering just because the number happens to cover it.
        $this->assertSame('insufficient', $engine->fitFor($country, 200, 2, 2, 7, mealStyle: 'jede_napolju'));
    }

    public function test_meal_style_sam_se_snalazim_uses_the_self_catering_path_directly(): void
    {
        // 'sam_se_snalazim' ("I'll organize myself / cook") — its own top-level meal_style,
        // 2026-08-14 second redesign, no longer a meal_plan_preference pick.
        $country = $this->countryWithPrices(meal: 10, coffee: 2); // eating_out 623, self_catering 178
        $engine = new BudgetEstimationEngine;

        $this->assertSame('self_catering', $engine->fitFor($country, 200, 2, 2, 7, mealStyle: 'sam_se_snalazim'));
        $this->assertSame('insufficient', $engine->fitFor($country, 50, 2, 2, 7, mealStyle: 'sam_se_snalazim'));
    }

    public function test_all_inclusive_fits_is_a_purely_informational_cross_check(): void
    {
        // Owner's own gradation, 2026-08-14: even for a session that picked "Local restaurants"
        // or "I'll organize myself," it's worth telling them separately whether all-inclusive
        // would ALSO fit for the same money — "biram restoran... mozemo mu kazemo negde imas
        // all inclusive za te pare." Uses the default 0.8 coefficient (discount), so
        // all-inclusive@498.4 is CHEAPER than plain eating_out@623 here.
        $country = $this->countryWithPrices(meal: 10, coffee: 2); // eating_out 623; sve_ukljuceno@0.8 = 498.4
        $engine = new BudgetEstimationEngine;

        $this->assertTrue($engine->allInclusiveFits($country, 500, 2, 2, 7));
        $this->assertFalse($engine->allInclusiveFits($country, 498, 2, 2, 7));
        // Accommodation total still comes off the top first, same as fitFor().
        $this->assertFalse($engine->allInclusiveFits($country, 500, 2, 2, 7, accommodationTotal: 100.0));
        // A destination with a real bundled (mealsIncluded) price just checks that price
        // directly against the budget — same shortcut as fitFor()'s $mealsIncluded branch.
        $this->assertTrue($engine->allInclusiveFits($country, 300, 2, 2, 7, accommodationTotal: 250.0, mealsIncluded: true));
        $this->assertFalse($engine->allInclusiveFits($country, 200, 2, 2, 7, accommodationTotal: 250.0, mealsIncluded: true));
    }

    public function test_multiple_meal_plan_picks_are_a_priority_list_not_a_contradiction(): void
    {
        // Owner's own framing, 2026-08-14: picking BOTH all-inclusive and a lighter tier means
        // "all-inclusive if it fits, breakfast if that's what it takes" — "teo bih da se uklopim
        // u all inclusive negde, al ako nema, pa mogu i da przim pomfrit iz kese na terasi."
        // Every pick is checked; the BEST one that actually fits wins, returned as its own real
        // slug — this is what lets a caller say "Egypt: all-inclusive, Turkey: only breakfast."
        //
        // Needs coefficient > 1 (a markup, not the default 0.8 discount) to even construct this
        // scenario — under the default discount, MORE coverage is always CHEAPER (more-inclusive
        // plans win the volume discount harder), so a budget that covers a lighter pick always
        // covers all-inclusive too; there's no way for the preferred pick to be the one that
        // DOESN'T fit unless coverage costs extra instead of less.
        $country = $this->countryWithPrices(meal: 10, coffee: 2); // eating_out 623
        $country->update(['meta' => [...$country->meta, 'meal_plan_coefficient' => 1.5]]);
        $engine = new BudgetEstimationEngine;
        $picks = ['sve_ukljuceno', 'dorucak'];
        // dorucak@1.5 coefficient = 660.38; sve_ukljuceno@1.5 = 934.5 (see other tests for the math).

        // Budget covers breakfast but not all-inclusive -> falls through to the one that fits.
        $this->assertSame('dorucak', $engine->fitFor($country, 661, 2, 2, 7, mealPlanSlugs: $picks));
        // Budget covers BOTH -> the more-preferred (all-inclusive) wins, not just whichever's cheapest.
        $this->assertSame('sve_ukljuceno', $engine->fitFor($country, 935, 2, 2, 7, mealPlanSlugs: $picks));
        // Budget covers neither.
        $this->assertSame('insufficient', $engine->fitFor($country, 100, 2, 2, 7, mealPlanSlugs: $picks));
    }

    /** food_total_eur (2026-09-01, backs GeographyResolver's budgetFitPercent) must reflect the
     *  WINNING tier fitFor() actually picked, not the most-preferred pick in the list — same
     *  scenario/math as test_multiple_meal_plan_picks_are_a_priority_list_not_a_contradiction
     *  (coefficient 1.5, dorucak@660.38 fits but sve_ukljuceno@934.5 doesn't at this budget). */
    public function test_narrow_candidates_food_total_reflects_the_winning_tier_not_the_most_preferred_one(): void
    {
        $country = $this->countryWithPrices(meal: 10, coffee: 2); // eating_out total = 623
        $country->update(['meta' => [...$country->meta, 'meal_plan_coefficient' => 1.5]]);
        $engine = new BudgetEstimationEngine;
        $picks = ['sve_ukljuceno', 'dorucak'];

        $result = $engine->narrowCandidates(collect([$country]), 661, 2, 2, 7, null, $picks);

        $this->assertSame('dorucak', $result->first()['fit']);
        $this->assertEqualsWithDelta(660.38, $result->first()['food_total_eur'], 0.01);
    }
}
