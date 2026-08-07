<?php

namespace Tests\Feature;

use App\GraphQL\Resolvers\SearchSessionResolver;
use App\Models\SearchSession;
use App\Models\TaxonomyNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the home_city_id wiring added 2026-07-13 (wizard_architecture "distance-from-home,
 * as its own wizard step" backlog item): the resolver writes the plain FK, and the session
 * exposes a computed distance — critically, picking a home city must NOT run through the
 * implies/suggests engine (it's arithmetic input, not a taxonomy preference).
 */
class HomeCityDistanceTest extends TestCase
{
    use RefreshDatabase;

    private function city(string $slug, ?float $lat, ?float $lng): TaxonomyNode
    {
        return TaxonomyNode::create([
            'type' => 'city', 'slug' => $slug, 'label' => $slug, 'sort_order' => 0,
            'meta' => $lat === null ? null : ['lat' => $lat, 'lng' => $lng],
        ]);
    }

    public function test_update_writes_home_city_id_and_session_computes_distance(): void
    {
        $beograd = $this->city('beograd', 44.7866, 20.4489);
        $prag = $this->city('prag', 50.0755, 14.4378);

        $session = SearchSession::create(['status' => 'in_progress', 'city_id' => $prag->id]);

        (new SearchSessionResolver)->update(null, [
            'id' => $session->id,
            'input' => ['homeCityId' => $beograd->id],
        ]);

        $session->refresh();

        $this->assertSame($beograd->id, $session->home_city_id);
        $this->assertEqualsWithDelta(741, $session->distanceFromHomeKm(), 15);
    }

    public function test_distance_is_null_until_both_cities_are_picked(): void
    {
        $beograd = $this->city('beograd', 44.7866, 20.4489);

        $session = SearchSession::create(['status' => 'in_progress', 'home_city_id' => $beograd->id]);

        $this->assertNull($session->distanceFromHomeKm());
    }

    public function test_picking_a_home_city_does_not_trigger_implies_suggests(): void
    {
        // Attach an implies edge FROM the home city node to a preference_tag, as a trap: if
        // homeCityId were ever added to SearchSessionResolver::TAXONOMY_FK_INPUT_KEYS, this
        // would start silently firing on every home-city pick, which is exactly what the
        // "own dedicated wizard step, not a preference_tag" decision was meant to prevent.
        $beograd = $this->city('beograd', 44.7866, 20.4489);
        $trapTag = TaxonomyNode::create(['type' => 'preference_tag', 'slug' => 'trap', 'label' => 'trap', 'sort_order' => 0]);
        $beograd->implies()->attach($trapTag->id, ['relation_type' => 'implies']);

        $session = SearchSession::create(['status' => 'in_progress']);

        (new SearchSessionResolver)->update(null, [
            'id' => $session->id,
            'input' => ['homeCityId' => $beograd->id],
        ]);

        $session->refresh();

        $this->assertArrayNotHasKey('implied_preference_tags', $session->free_text_answers ?? []);
    }
}
