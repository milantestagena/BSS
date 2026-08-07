<?php

namespace Tests\Unit;

use App\Models\TaxonomyNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the two geography helpers added 2026-07-13: TaxonomyNode::distanceKmTo (haversine over
 * meta.lat/meta.lng) and TaxonomyNode::seasonalWindowFor (meta payload on a seasonal_window
 * edge). Both are backlog items from wizard_architecture's 2026-07-12 brainstorm.
 */
class TaxonomyNodeGeoTest extends TestCase
{
    use RefreshDatabase;

    private function node(string $type, string $slug, ?array $meta = null): TaxonomyNode
    {
        return TaxonomyNode::create([
            'type' => $type, 'slug' => $slug, 'label' => $slug, 'sort_order' => 0, 'meta' => $meta,
        ]);
    }

    public function test_distance_km_to_is_roughly_correct_between_known_cities(): void
    {
        // Belgrade <-> Prague great-circle distance is ~741km.
        $beograd = $this->node('city', 'beograd', ['lat' => 44.7866, 'lng' => 20.4489]);
        $prag = $this->node('city', 'prag', ['lat' => 50.0755, 'lng' => 14.4378]);

        $distance = $beograd->distanceKmTo($prag);

        $this->assertEqualsWithDelta(741, $distance, 15);
    }

    public function test_distance_km_to_is_symmetric(): void
    {
        $beograd = $this->node('city', 'beograd', ['lat' => 44.7866, 'lng' => 20.4489]);
        $prag = $this->node('city', 'prag', ['lat' => 50.0755, 'lng' => 14.4378]);

        $this->assertEqualsWithDelta($beograd->distanceKmTo($prag), $prag->distanceKmTo($beograd), 0.001);
    }

    public function test_distance_km_to_is_null_when_either_side_lacks_coordinates(): void
    {
        $beograd = $this->node('city', 'beograd', ['lat' => 44.7866, 'lng' => 20.4489]);
        $noCoords = $this->node('city', 'no_coords');

        $this->assertNull($beograd->distanceKmTo($noCoords));
    }

    public function test_seasonal_window_for_returns_the_months_payload(): void
    {
        $grcka = $this->node('country', 'grcka');
        $letovanje = $this->node('termin_category', 'letovanje');
        $zimovanje = $this->node('termin_category', 'zimovanje');

        $grcka->seasonalWindows()->attach($letovanje->id, [
            'relation_type' => 'seasonal_window',
            'meta' => json_encode(['months' => [6, 7, 8, 9]]),
        ]);

        $this->assertSame(['months' => [6, 7, 8, 9]], $grcka->seasonalWindowFor($letovanje));
        $this->assertNull($grcka->seasonalWindowFor($zimovanje));
    }
}
