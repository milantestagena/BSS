<?php

namespace Tests\Unit;

use App\Models\Location;
use App\Models\TaxonomyNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the Location <-> TaxonomyNode link added 2026-07-13 — a deliberately separate table
 * from taxonomy_nodes (Booking's raw location catalog vs our curated content tree), joined by
 * a nullable FK. See the create_locations_table migration comment.
 */
class LocationLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_taxonomy_node_can_be_linked_to_a_location(): void
    {
        $location = Location::create([
            'booking_dest_id' => 'test_x_city', 'dest_type' => 'city', 'name' => 'Testgrad', 'source' => 'manual_test',
        ]);

        $city = TaxonomyNode::create([
            'type' => 'city', 'slug' => 'testgrad', 'label' => 'Testgrad', 'sort_order' => 0,
            'booking_location_id' => $location->id,
        ]);

        $this->assertSame($location->id, $city->bookingLocation->id);
        $this->assertSame('test_x_city', $city->bookingLocation->booking_dest_id);
    }

    public function test_a_taxonomy_node_without_a_match_has_a_null_booking_location(): void
    {
        $city = TaxonomyNode::create(['type' => 'city', 'slug' => 'nomatch', 'label' => 'No match', 'sort_order' => 0]);

        $this->assertNull($city->bookingLocation);
    }

    public function test_most_locations_have_no_linked_taxonomy_node(): void
    {
        $location = Location::create([
            'booking_dest_id' => 'test_landmark', 'dest_type' => 'landmark', 'name' => 'Random landmark', 'source' => 'manual_test',
        ]);

        $this->assertCount(0, $location->taxonomyNodes);
    }
}
