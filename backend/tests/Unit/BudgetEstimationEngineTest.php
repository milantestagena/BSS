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

        $this->assertSame('meal_plan', $engine->fitFor($country, 623, 2, 2, 7, mealPlanSlug: 'dorucak'));
        $this->assertSame('insufficient', $engine->fitFor($country, 622, 2, 2, 7, mealPlanSlug: 'dorucak'));
        $this->assertSame('meal_plan', $engine->fitFor($country, 623, 2, 2, 7, mealPlanSlug: 'sve_ukljuceno'));
        $this->assertSame('insufficient', $engine->fitFor($country, 622, 2, 2, 7, mealPlanSlug: 'sve_ukljuceno'));
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
        $this->assertSame('meal_plan', $engine->fitFor($country, 609, 2, 2, 7, mealPlanSlug: 'dorucak'));
        $this->assertSame('insufficient', $engine->fitFor($country, 608, 2, 2, 7, mealPlanSlug: 'dorucak'));

        // sve_ukljuceno: coveredFraction 1.0 -> 623 * 0.8 = 498.4
        $this->assertSame('meal_plan', $engine->fitFor($country, 499, 2, 2, 7, mealPlanSlug: 'sve_ukljuceno'));
        $this->assertSame('insufficient', $engine->fitFor($country, 498, 2, 2, 7, mealPlanSlug: 'sve_ukljuceno'));
    }

    public function test_all_inclusive_costs_more_than_eating_out_when_coefficient_above_one(): void
    {
        $country = $this->countryWithPrices(meal: 10, coffee: 2); // eating_out total = 623
        $country->update(['meta' => [...$country->meta, 'meal_plan_coefficient' => 1.5]]);
        $engine = new BudgetEstimationEngine;

        // sve_ukljuceno covers the FULL 2.5 ratio, so its total is exactly eatingOutTotal * 1.5 = 934.5.
        $this->assertSame('meal_plan', $engine->fitFor($country, 935, 2, 2, 7, mealPlanSlug: 'sve_ukljuceno'));
        $this->assertSame('insufficient', $engine->fitFor($country, 934, 2, 2, 7, mealPlanSlug: 'sve_ukljuceno'));
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
        $this->assertSame('meal_plan', $engine->fitFor($country, 661, 2, 2, 7, mealPlanSlug: 'dorucak'));
        $this->assertSame('insufficient', $engine->fitFor($country, 660, 2, 2, 7, mealPlanSlug: 'dorucak'));
    }

    public function test_self_catering_flag_uses_the_existing_self_catering_path_regardless_of_meal_plan_slug(): void
    {
        // Owner's call, 2026-08-13: self-catering split into its own mandatory meal_style
        // question — $selfCatering now takes priority over any meal_plan_preference slug.
        $country = $this->countryWithPrices(meal: 10, coffee: 2); // eating_out 623, self_catering ~178
        $engine = new BudgetEstimationEngine;

        $this->assertSame('self_catering', $engine->fitFor($country, 200, 2, 2, 7, selfCatering: true));
        $this->assertSame('insufficient', $engine->fitFor($country, 50, 2, 2, 7, selfCatering: true));
        // Even with a meal_plan_preference also set, selfCatering wins.
        $this->assertSame('self_catering', $engine->fitFor($country, 200, 2, 2, 7, mealPlanSlug: 'sve_ukljuceno', selfCatering: true));
    }

    public function test_strongest_meal_plan_slug_picks_the_most_demanding_pick(): void
    {
        $this->assertSame('sve_ukljuceno', BudgetEstimationEngine::strongestMealPlanSlug(['dorucak', 'sve_ukljuceno']));
        $this->assertSame('dorucak_rucak', BudgetEstimationEngine::strongestMealPlanSlug(['dorucak_rucak', 'dorucak_vecera']));
        $this->assertNull(BudgetEstimationEngine::strongestMealPlanSlug([]));
    }
}
