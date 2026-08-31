<?php

namespace Tests\Unit;

use App\Models\TaxonomyNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers TaxonomyNode::offersMealPlan()/offeredMealPlanSlugs() — the data layer for real,
 * per-destination meal-plan availability (owner's ask, 2026-08-31, after a live Booking capture
 * showed Turkey doesn't offer "Breakfast & lunch" or "All meals included" even though the
 * wizard's meal_plan_preference question lets a session pick either). Filtering/ranking
 * behavior on a mismatch is a separate, not-yet-decided concern — these tests only cover
 * whether a destination is correctly reported as offering (or not offering) a given plan.
 */
class TaxonomyNodeMealPlanAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function node(string $type, string $slug, ?int $parentId = null): TaxonomyNode
    {
        return TaxonomyNode::create([
            'type' => $type, 'slug' => $slug, 'label' => $slug, 'parent_id' => $parentId, 'sort_order' => 0,
        ]);
    }

    public function test_country_reports_only_its_own_researched_meal_plans(): void
    {
        $country = $this->node('country', 'turska');
        $dorucak = $this->node('meal_plan', 'dorucak');
        $dorucakVecera = $this->node('meal_plan', 'dorucak_vecera');
        $sveUkljuceno = $this->node('meal_plan', 'sve_ukljuceno');
        $this->node('meal_plan', 'dorucak_rucak'); // exists as a real option, just not attached — proves exclusion isn't "node doesn't exist"

        $country->offersMealPlan()->attach([$dorucak->id, $dorucakVecera->id, $sveUkljuceno->id], ['relation_type' => 'offers_meal_plan']);

        $slugs = $country->offeredMealPlanSlugs();

        $this->assertTrue($slugs->contains('dorucak'));
        $this->assertTrue($slugs->contains('dorucak_vecera'));
        $this->assertTrue($slugs->contains('sve_ukljuceno'));
        $this->assertFalse($slugs->contains('dorucak_rucak'));
        $this->assertTrue($country->offersMealPlanSlug('sve_ukljuceno'));
        $this->assertFalse($country->offersMealPlanSlug('dorucak_rucak'));
    }

    public function test_city_with_no_edges_of_its_own_inherits_the_country_set(): void
    {
        $country = $this->node('country', 'turska');
        $city = $this->node('city', 'alanya', $country->id);
        $dorucak = $this->node('meal_plan', 'dorucak');
        $country->offersMealPlan()->attach($dorucak->id, ['relation_type' => 'offers_meal_plan']);

        // Not researched at the city level yet — inherits the country's set, same "not yet
        // researched" vs. "researched and genuinely offers none" distinction as culturalTierFor.
        $this->assertTrue($city->offersMealPlanSlug('dorucak'));
    }

    public function test_city_with_its_own_edges_does_not_fall_back_to_the_country(): void
    {
        $country = $this->node('country', 'turska');
        $city = $this->node('city', 'alanya', $country->id);
        $dorucak = $this->node('meal_plan', 'dorucak');
        $sveUkljuceno = $this->node('meal_plan', 'sve_ukljuceno');

        // Country offers both; this specific city (researched separately) only really offers one.
        $country->offersMealPlan()->attach([$dorucak->id, $sveUkljuceno->id], ['relation_type' => 'offers_meal_plan']);
        $city->offersMealPlan()->attach($dorucak->id, ['relation_type' => 'offers_meal_plan']);

        $this->assertTrue($city->offersMealPlanSlug('dorucak'));
        $this->assertFalse($city->offersMealPlanSlug('sve_ukljuceno'));
    }

    public function test_node_with_no_edges_and_no_parent_offers_nothing(): void
    {
        $country = $this->node('country', 'turska');

        $this->assertTrue($country->offeredMealPlanSlugs()->isEmpty());
        $this->assertFalse($country->offersMealPlanSlug('dorucak'));
    }
}
