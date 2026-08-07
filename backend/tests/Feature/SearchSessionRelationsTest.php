<?php

namespace Tests\Feature;

use App\GraphQL\Resolvers\SearchSessionResolver;
use App\Models\SearchSession;
use App\Models\TaxonomyNode;
use App\Models\WizardStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers SearchSessionResolver::applyImpliedAndSuggested / applyRelationTargets — the
 * implies/suggests engine flagged as untested in wizard_architecture's "known debt" (2026-07-12).
 * Each test seeds only the minimal taxonomy nodes + WizardQuestion rows it needs, not the full
 * WizardSeeder, so failures point at the specific mechanism under test.
 */
class SearchSessionRelationsTest extends TestCase
{
    use RefreshDatabase;

    private function node(string $type, string $slug): TaxonomyNode
    {
        return TaxonomyNode::create([
            'type' => $type,
            'slug' => $slug,
            'label' => $slug,
            'sort_order' => 0,
        ]);
    }

    private function questionFor(string $taxonomyType, string $sessionField): void
    {
        $step = WizardStep::create(['key' => "step_{$taxonomyType}", 'label' => $taxonomyType, 'sort_order' => 0]);
        $step->questions()->create([
            'key' => "q_{$taxonomyType}",
            'label' => $taxonomyType,
            'input_type' => 'taxonomy_choice',
            'taxonomy_type' => $taxonomyType,
            'session_field' => $sessionField,
        ]);
    }

    public function test_implies_adds_to_implied_preference_tags_without_touching_explicit_tags(): void
    {
        $gurman = $this->node('persona', 'gurman');
        $dobraHrana = $this->node('preference_tag', 'dobra_hrana');
        $pivo = $this->node('preference_tag', 'pivo');
        $this->questionFor('preference_tag', 'free_text_answers.preference_tags');

        $gurman->implies()->attach($dobraHrana->id, ['relation_type' => 'implies']);

        $session = SearchSession::create(['status' => 'in_progress']);

        (new SearchSessionResolver)->update(null, [
            'id' => $session->id,
            'input' => [
                'personaId' => $gurman->id,
                'freeTextAnswers' => ['preference_tags' => [$pivo->slug]],
            ],
        ]);

        $session->refresh();

        $this->assertSame(['pivo'], $session->free_text_answers['preference_tags']);
        $this->assertSame(['dobra_hrana'], $session->free_text_answers['implied_preference_tags']);
    }

    public function test_suggests_prefills_empty_field(): void
    {
        $jeftino = $this->node('preference_tag', 'jeftino');
        $budgetTier = $this->node('budget_tier', 'do_20e');
        $this->questionFor('budget_tier', 'budget_tier_id');

        $jeftino->suggests()->attach($budgetTier->id, ['relation_type' => 'suggests']);

        $session = SearchSession::create(['status' => 'in_progress']);

        (new SearchSessionResolver)->update(null, [
            'id' => $session->id,
            'input' => ['freeTextAnswers' => ['preference_tags' => ['jeftino']]],
        ]);

        $session->refresh();

        $this->assertSame($budgetTier->id, $session->budget_tier_id);
    }

    public function test_suggests_does_not_overwrite_an_already_answered_field(): void
    {
        $jeftino = $this->node('preference_tag', 'jeftino');
        $cheapTier = $this->node('budget_tier', 'do_20e');
        $expensiveTier = $this->node('budget_tier', '100e_plus');
        $this->questionFor('budget_tier', 'budget_tier_id');

        $jeftino->suggests()->attach($cheapTier->id, ['relation_type' => 'suggests']);

        // User already picked the expensive tier explicitly, in an earlier request.
        $session = SearchSession::create(['status' => 'in_progress', 'budget_tier_id' => $expensiveTier->id]);

        (new SearchSessionResolver)->update(null, [
            'id' => $session->id,
            'input' => ['freeTextAnswers' => ['preference_tags' => ['jeftino']]],
        ]);

        $session->refresh();

        $this->assertSame($expensiveTier->id, $session->budget_tier_id);
    }

    public function test_one_node_can_suggest_multiple_different_taxonomy_types_at_once(): void
    {
        $budgetConscious = $this->node('preference_tag', 'budget_conscious');
        $cheapTier = $this->node('budget_tier', 'do_20e');
        $hostel = $this->node('tip_smestaja', 'hostel');
        $this->questionFor('budget_tier', 'budget_tier_id');
        $this->questionFor('tip_smestaja', 'tip_smestaja_id');

        $budgetConscious->suggests()->attach($cheapTier->id, ['relation_type' => 'suggests']);
        $budgetConscious->suggests()->attach($hostel->id, ['relation_type' => 'suggests']);

        $session = SearchSession::create(['status' => 'in_progress']);

        (new SearchSessionResolver)->update(null, [
            'id' => $session->id,
            'input' => ['freeTextAnswers' => ['preference_tags' => ['budget_conscious']]],
        ]);

        $session->refresh();

        $this->assertSame($cheapTier->id, $session->budget_tier_id);
        $this->assertSame($hostel->id, $session->tip_smestaja_id);
    }

    public function test_implies_suggests_resolution_is_single_hop_not_transitive(): void
    {
        // Documents current (2026-07-12) behavior per wizard_architecture: a node written as
        // a RESULT of one implies/suggests hop is not itself re-walked for its own edges in
        // the same request. If this becomes recursive later, this test should start failing
        // and needs to be updated deliberately, not silently.
        $gurman = $this->node('persona', 'gurman');
        $dobraHrana = $this->node('preference_tag', 'dobra_hrana');
        $budgetTier = $this->node('budget_tier', '50_100e');
        $this->questionFor('preference_tag', 'free_text_answers.preference_tags');
        $this->questionFor('budget_tier', 'budget_tier_id');

        $gurman->implies()->attach($dobraHrana->id, ['relation_type' => 'implies']);
        $dobraHrana->suggests()->attach($budgetTier->id, ['relation_type' => 'suggests']);

        $session = SearchSession::create(['status' => 'in_progress']);

        (new SearchSessionResolver)->update(null, [
            'id' => $session->id,
            'input' => ['personaId' => $gurman->id],
        ]);

        $session->refresh();

        $this->assertSame(['dobra_hrana'], $session->free_text_answers['implied_preference_tags']);
        $this->assertNull($session->budget_tier_id);
    }
}
