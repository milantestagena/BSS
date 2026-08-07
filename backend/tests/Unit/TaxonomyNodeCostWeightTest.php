<?php

namespace Tests\Unit;

use App\Models\TaxonomyNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers TaxonomyNode::weightToward — the weighted_toward mechanism added 2026-07-13 that lets
 * any taxonomy node (persona, preference_tag, ...) declare how much it cares about a
 * cost_category (hospitality / local_stores / transport), independent of what else is selected
 * in the same session. See wizard_architecture's "Gurman in a hostel" scenario.
 */
class TaxonomyNodeCostWeightTest extends TestCase
{
    use RefreshDatabase;

    private function node(string $type, string $slug): TaxonomyNode
    {
        return TaxonomyNode::create(['type' => $type, 'slug' => $slug, 'label' => $slug, 'sort_order' => 0]);
    }

    public function test_weight_toward_returns_the_seeded_weight(): void
    {
        $gurman = $this->node('persona', 'gurman');
        $hospitality = $this->node('cost_category', 'hospitality');

        $gurman->weightedToward()->attach($hospitality->id, [
            'relation_type' => 'weighted_toward',
            'meta' => json_encode(['weight' => 3]),
        ]);

        $this->assertSame(3, $gurman->weightToward($hospitality));
    }

    public function test_weight_toward_is_null_when_no_edge_exists(): void
    {
        $gurman = $this->node('persona', 'gurman');
        $transport = $this->node('cost_category', 'transport');

        $this->assertNull($gurman->weightToward($transport));
    }

    public function test_a_persona_keeps_its_own_weight_regardless_of_accommodation_choice(): void
    {
        // The "Gurman in a hostel" scenario: the persona's weight toward hospitality doesn't
        // depend on, or get cancelled by, a differently-weighted node (here standing in for
        // tip_smestaja, which isn't seeded yet) pointing at a different cost_category.
        $gurman = $this->node('persona', 'gurman');
        $hostelStandIn = $this->node('preference_tag', 'jeftino');
        $hospitality = $this->node('cost_category', 'hospitality');
        $localStores = $this->node('cost_category', 'local_stores');

        $gurman->weightedToward()->attach($hospitality->id, ['relation_type' => 'weighted_toward', 'meta' => json_encode(['weight' => 3])]);
        $hostelStandIn->weightedToward()->attach($localStores->id, ['relation_type' => 'weighted_toward', 'meta' => json_encode(['weight' => 3])]);

        $this->assertSame(3, $gurman->weightToward($hospitality));
        $this->assertNull($gurman->weightToward($localStores));
        $this->assertSame(3, $hostelStandIn->weightToward($localStores));
    }
}
