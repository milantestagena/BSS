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
}
