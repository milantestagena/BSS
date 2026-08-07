<?php

namespace App\GraphQL\Resolvers;

use App\Models\SearchSession;
use App\Models\TaxonomyNode;
use App\Models\WizardCampaign;
use App\Models\WizardQuestion;
use Illuminate\Support\Collection;

class SearchSessionResolver
{
    /** Input keys that carry a single taxonomy_nodes.id — the ones that can trigger implies/suggests. */
    private const TAXONOMY_FK_INPUT_KEYS = [
        'tripTypeId', 'groupTypeId', 'personaId', 'countryRegionId', 'cityId', 'budgetTierId', 'tipSmestajaId',
    ];

    public function start(): SearchSession
    {
        return SearchSession::create([
            'status' => 'in_progress',
        ]);
    }

    /**
     * Starts a session under a themed campaign and applies its preset_answers directly (plain
     * column assignment, not routed through applyImpliedAndSuggested — GeographyResolver
     * re-derives excludes/cultural/budget narrowing fresh from session state on every read, so
     * there's nothing that needs to "fire" at write time here). See WizardCampaign, and
     * wizard_architecture memory 2026-07-30.
     */
    public function startCampaign($_, array $args): SearchSession
    {
        $campaign = WizardCampaign::where('key', $args['campaignKey'])->firstOrFail();

        $session = SearchSession::create(['status' => 'in_progress', 'wizard_campaign_id' => $campaign->id]);

        if ($campaign->preset_answers) {
            $session->fill($campaign->preset_answers);
            $session->save();
        }

        return $session;
    }

    public function update($_, array $args): SearchSession
    {
        $session = SearchSession::findOrFail($args['id']);
        $input = $args['input'];

        $columnMap = [
            'tripTypeId' => 'trip_type_id',
            'adultsCount' => 'adults_count',
            'childrenAges' => 'children_ages',
            'needsCrib' => 'needs_crib',
            'numberOfRooms' => 'number_of_rooms',
            'totalBudget' => 'total_budget',
            'groupTypeId' => 'group_type_id',
            'personaId' => 'persona_id',
            'budgetTierId' => 'budget_tier_id',
            'tipSmestajaId' => 'tip_smestaja_id',
            'terminCategory' => 'termin_category',
            'dateFrom' => 'date_from',
            'dateTo' => 'date_to',
            'countryRegionId' => 'country_region_id',
            'cityId' => 'city_id',
            // Deliberately NOT in TAXONOMY_FK_INPUT_KEYS: home city is where the user travels
            // FROM, arithmetic input for distanceFromHomeKm — it must never trigger destination-
            // oriented implies/suggests/excludes (see wizard_architecture).
            'homeCityId' => 'home_city_id',
            'status' => 'status',
        ];

        foreach ($columnMap as $inputKey => $column) {
            if (array_key_exists($inputKey, $input) && $input[$inputKey] !== null) {
                $session->{$column} = $input[$inputKey];
            }
        }

        // free_text_answers is a jsonb bag written to progressively by different steps
        // (persona region_theme, preference_tags, smestaj_preference...), so incoming keys
        // are merged rather than overwriting the whole column. Implied preference tags live
        // under a SEPARATE key (`implied_preference_tags`, written in applyImpliedAndSuggested
        // below) rather than mixed into the user's own `preference_tags` array — otherwise the
        // user could never un-check something the frontend re-submits its full explicit list
        // for, and a plain array_merge() on the shared key would also just replace it outright
        // (PHP's array_merge on matching string keys overwrites, it doesn't union).
        if (! empty($input['freeTextAnswers'])) {
            $session->free_text_answers = array_merge(
                $session->free_text_answers ?? [],
                $input['freeTextAnswers']
            );
        }

        $this->applyImpliedAndSuggested($session, $input);

        $session->save();

        return $session;
    }

    /**
     * For every taxonomy node newly selected in this request (single FKs like personaId, plus
     * preference_tags slugs), auto-apply its `implies`/`suggests` targets — driven entirely by
     * the taxonomy_node_relations table, not hardcoded per taxonomy type. First-touch-wins:
     * never overwrite a field the user (or an earlier implication) already answered.
     */
    private function applyImpliedAndSuggested(SearchSession $session, array $input): void
    {
        $selectedNodes = collect();

        foreach (self::TAXONOMY_FK_INPUT_KEYS as $key) {
            if (! empty($input[$key])) {
                $selectedNodes->push(TaxonomyNode::find($input[$key]));
            }
        }

        if (! empty($input['freeTextAnswers']['preference_tags'])) {
            $tagSlugs = $input['freeTextAnswers']['preference_tags'];
            $selectedNodes = $selectedNodes->merge(
                TaxonomyNode::where('type', 'preference_tag')->whereIn('slug', $tagSlugs)->get()
            );
        }

        foreach ($selectedNodes->filter() as $node) {
            $this->applyRelationTargets($session, $node->implies);
            $this->applyRelationTargets($session, $node->suggests);
        }
    }

    private function applyRelationTargets(SearchSession $session, Collection $targets): void
    {
        foreach ($targets as $target) {
            $question = WizardQuestion::where('taxonomy_type', $target->type)->first();

            if (! $question || ! $question->session_field) {
                continue;
            }

            $field = $question->session_field;

            if (str_starts_with($field, 'free_text_answers.')) {
                $key = substr($field, strlen('free_text_answers.'));
                $current = $session->free_text_answers ?? [];

                if ($key === 'preference_tags') {
                    // Written to `implied_preference_tags`, not `preference_tags` itself — see
                    // the merge comment in update(). GeographyResolver's scoring and
                    // SearchSession::selectedTaxonomyNodeIds() both read this key too, so an
                    // implied tag still counts everywhere a user-picked one would.
                    $tags = collect($current['implied_preference_tags'] ?? []);
                    if (! $tags->contains($target->slug)) {
                        $current['implied_preference_tags'] = $tags->push($target->slug)->values()->all();
                    }
                } elseif (empty($current[$key])) {
                    $current[$key] = $target->slug;
                }

                $session->free_text_answers = $current;
            } elseif (str_ends_with($field, '_id')) {
                if (empty($session->{$field})) {
                    $session->{$field} = $target->id;
                }
            } elseif (empty($session->{$field})) {
                $session->{$field} = $target->slug;
            }
        }
    }
}
