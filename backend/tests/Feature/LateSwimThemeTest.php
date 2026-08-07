<?php

namespace Tests\Feature;

use App\GraphQL\Resolvers\GeographyResolver;
use App\Models\SearchSession;
use App\Models\TaxonomyNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end coverage for the first themed entry point ("kasno_kupanje" / "još malo sunca",
 * 2026-07-14): picking this termin_category should narrow region_theme/country/city choices to
 * swim-relevant geography, via plain excludes edges — this is also the regression test for the
 * selectedTaxonomyNodeIds() termin_category bug fixed the same day (without that fix, none of
 * these excludes would ever apply).
 */
class LateSwimThemeTest extends TestCase
{
    use RefreshDatabase;

    private function node(string $type, string $slug, ?int $parentId = null): TaxonomyNode
    {
        return TaxonomyNode::create([
            'type' => $type, 'slug' => $slug, 'label' => $slug, 'sort_order' => 0, 'parent_id' => $parentId,
        ]);
    }

    public function test_picking_the_theme_excludes_non_swim_region_themes(): void
    {
        $theme = $this->node('termin_category', 'kasno_kupanje');
        $istocna = $this->node('region_theme', 'istocna_evropa');
        $mediteran = $this->node('region_theme', 'mediteran');

        $theme->excludes()->attach($istocna->id, ['relation_type' => 'excludes']);

        $session = SearchSession::create(['status' => 'in_progress', 'termin_category' => 'kasno_kupanje']);

        $results = (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'region_theme']);

        $this->assertFalse($results->pluck('id')->contains($istocna->id));
        $this->assertTrue($results->pluck('id')->contains($mediteran->id));
    }

    public function test_picking_the_theme_excludes_specific_non_swim_cities_but_not_their_mixed_country(): void
    {
        $theme = $this->node('termin_category', 'kasno_kupanje');
        $grcka = $this->node('country', 'grcka');
        $atina = $this->node('city', 'atina', $grcka->id);
        $krit = $this->node('city', 'krit', $grcka->id);

        $theme->excludes()->attach($atina->id, ['relation_type' => 'excludes']);

        $session = SearchSession::create(['status' => 'in_progress', 'termin_category' => 'kasno_kupanje']);

        // The country itself is NOT excluded (grcka has valid swim children).
        $countryResults = (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'country']);
        $this->assertTrue($countryResults->pluck('id')->contains($grcka->id));

        // But the specific non-swim city under it is filtered out, the swim one stays.
        $cityResults = (new GeographyResolver)->suggested(null, ['sessionId' => $session->id, 'type' => 'city', 'parentId' => $grcka->id]);
        $this->assertFalse($cityResults->pluck('id')->contains($atina->id));
        $this->assertTrue($cityResults->pluck('id')->contains($krit->id));
    }

    public function test_without_the_selectedTaxonomyNodeIds_fix_this_would_silently_do_nothing(): void
    {
        // Documents WHY the fix matters: termin_category must resolve to a real node id for
        // any excludes/implies authored from it to have any effect at all.
        $theme = $this->node('termin_category', 'kasno_kupanje');
        $atina = $this->node('city', 'atina');
        $theme->excludes()->attach($atina->id, ['relation_type' => 'excludes']);

        $session = SearchSession::create(['status' => 'in_progress', 'termin_category' => 'kasno_kupanje']);

        $this->assertTrue($session->selectedTaxonomyNodeIds()->contains($theme->id));
    }
}
