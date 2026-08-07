<?php

namespace Tests\Unit;

use App\Models\TaxonomyNode;
use App\Models\TaxonomyNodeClimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers TaxonomyNode::climateFor — monthly climate lookup with parent fallback (city -> country)
 * added 2026-07-13, same session as the cost_category/weighted_toward mechanism.
 */
class TaxonomyNodeClimateTest extends TestCase
{
    use RefreshDatabase;

    public function test_climate_for_returns_the_seeded_month(): void
    {
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'Testgrad', 'sort_order' => 0]);

        TaxonomyNodeClimate::create([
            'taxonomy_node_id' => $city->id, 'month' => 7,
            'avg_temp_c' => 25, 'precip_mm' => 10, 'sun_hours' => 300, 'source' => 'manual_estimate',
        ]);

        $july = $city->climateFor(7);

        $this->assertNotNull($july);
        $this->assertSame(25.0, $july->avg_temp_c);
        $this->assertNull($city->climateFor(1));
    }

    public function test_climate_for_falls_back_to_parent_when_child_has_no_data(): void
    {
        $country = TaxonomyNode::create(['type' => 'country', 'slug' => 'testland', 'label' => 'Testland', 'sort_order' => 0]);
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'Testgrad', 'parent_id' => $country->id, 'sort_order' => 0]);

        TaxonomyNodeClimate::create([
            'taxonomy_node_id' => $country->id, 'month' => 7, 'avg_temp_c' => 22,
        ]);

        $climate = $city->climateFor(7);

        $this->assertNotNull($climate);
        $this->assertSame(22.0, $climate->avg_temp_c);
        $this->assertSame($country->id, $climate->taxonomy_node_id);
    }

    public function test_climate_for_is_null_when_neither_node_nor_parent_has_data(): void
    {
        $country = TaxonomyNode::create(['type' => 'country', 'slug' => 'testland', 'label' => 'Testland', 'sort_order' => 0]);
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'Testgrad', 'parent_id' => $country->id, 'sort_order' => 0]);

        $this->assertNull($city->climateFor(7));
    }
}
