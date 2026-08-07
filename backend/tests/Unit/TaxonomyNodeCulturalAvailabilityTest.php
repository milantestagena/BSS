<?php

namespace Tests\Unit;

use App\Models\CulturalAvailability;
use App\Models\TaxonomyNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers TaxonomyNode::culturalTierFor — same parent-fallback pattern as climateFor, see
 * wizard_architecture memory, 2026-07-30.
 */
class TaxonomyNodeCulturalAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cultural_tier_for_returns_the_seeded_category(): void
    {
        $country = TaxonomyNode::create(['type' => 'country', 'slug' => 'testland', 'label' => 'test', 'sort_order' => 0]);
        CulturalAvailability::create(['taxonomy_node_id' => $country->id, 'category' => 'alcohol', 'tier' => 2, 'label' => 'test']);

        $result = $country->culturalTierFor('alcohol');

        $this->assertNotNull($result);
        $this->assertSame(2, $result->tier);
        $this->assertNull($country->culturalTierFor('pork'));
    }

    public function test_cultural_tier_for_falls_back_to_parent(): void
    {
        $country = TaxonomyNode::create(['type' => 'country', 'slug' => 'testland', 'label' => 'test', 'sort_order' => 0]);
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'testgrad', 'label' => 'test', 'parent_id' => $country->id, 'sort_order' => 0]);
        CulturalAvailability::create(['taxonomy_node_id' => $country->id, 'category' => 'alcohol', 'tier' => 1, 'label' => 'test']);

        $result = $city->culturalTierFor('alcohol');

        $this->assertSame(1, $result->tier);
        $this->assertSame($country->id, $result->taxonomy_node_id);
    }
}
