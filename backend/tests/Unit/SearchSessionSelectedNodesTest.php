<?php

namespace Tests\Unit;

use App\Models\SearchSession;
use App\Models\TaxonomyNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the 2026-07-14 fix to SearchSession::selectedTaxonomyNodeIds() — termin_category is
 * stored as a bare slug string (not an `_id` FK, see search_sessions migration) and was missing
 * from this method entirely, meaning implies/excludes edges authored FROM a termin_category
 * node (e.g. a themed entry point) silently never applied. See wizard_architecture.
 */
class SearchSessionSelectedNodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_termin_category_slug_resolves_to_its_taxonomy_node_id(): void
    {
        $letovanje = TaxonomyNode::create([
            'type' => 'termin_category', 'slug' => 'letovanje', 'label' => 'Summer', 'sort_order' => 0,
        ]);

        $session = SearchSession::create(['status' => 'in_progress', 'termin_category' => 'letovanje']);

        $this->assertTrue($session->selectedTaxonomyNodeIds()->contains($letovanje->id));
    }

    public function test_unset_termin_category_does_not_error_or_add_anything(): void
    {
        $session = SearchSession::create(['status' => 'in_progress']);

        $this->assertCount(0, $session->selectedTaxonomyNodeIds());
    }

    public function test_termin_category_with_no_matching_taxonomy_node_is_silently_ignored(): void
    {
        // Defensive: a typo'd or stale termin_category value shouldn't blow up id resolution.
        $session = SearchSession::create(['status' => 'in_progress', 'termin_category' => 'nepostojeci_slug']);

        $this->assertCount(0, $session->selectedTaxonomyNodeIds());
    }
}
