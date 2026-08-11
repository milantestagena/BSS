<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class SearchSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'wizard_campaign_id',
        'trip_type_id',
        'adults_count',
        'children_ages',
        'needs_crib',
        'number_of_rooms',
        'total_budget',
        'group_type_id',
        'persona_id',
        'budget_tier_id',
        'tip_smestaja_id',
        'termin_category',
        'date_from',
        'date_to',
        'country_region_id',
        'city_id',
        'home_city_id',
        'free_text_answers',
        'status',
        'ai_credit_charged_at',
    ];

    protected $casts = [
        'total_budget' => 'float',
        'children_ages' => 'array',
        'needs_crib' => 'array',
        'free_text_answers' => 'array',
        'date_from' => 'date',
        'date_to' => 'date',
        'ai_credit_charged_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tripType(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'trip_type_id');
    }

    /** Which campaign this session was started from (see SearchSessionResolver::startCampaign)
     *  — null for the generic '' route. See WizardCampaignDestinationPrice, 2026-08-05. */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WizardCampaign::class, 'wizard_campaign_id');
    }

    public function groupType(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'group_type_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'persona_id');
    }

    public function budgetTier(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'budget_tier_id');
    }

    public function tipSmestaja(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'tip_smestaja_id');
    }

    public function countryRegion(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'country_region_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'city_id');
    }

    public function homeCity(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'home_city_id');
    }

    /**
     * Distance in km between the session's home city and its chosen destination city, or null
     * if either isn't picked yet or is missing lat/lng meta. See TaxonomyNode::distanceKmTo.
     */
    public function distanceFromHomeKm(): ?float
    {
        if (! $this->home_city_id || ! $this->city_id) {
            return null;
        }

        return $this->homeCity?->distanceKmTo($this->city);
    }

    /**
     * All taxonomy nodes currently selected on this session (trip type, group type, persona,
     * country/region, city, budget tier, accommodation type). Used to generically resolve
     * `implies`/`suggests`/`excludes` relationships without hardcoding which field means what.
     */
    public function selectedTaxonomyNodes(): Collection
    {
        return collect([
            $this->tripType, $this->groupType, $this->persona,
            $this->countryRegion, $this->city, $this->budgetTier, $this->tipSmestaja,
        ])->filter();
    }

    /**
     * Same as selectedTaxonomyNodes() but IDs only (including preference_tags, which are
     * stored as bare slugs in free_text_answers, not FKs) — the shape most resolvers actually
     * need when computing applicable excludes/implies/suggests via set operations.
     */
    public function selectedTaxonomyNodeIds(): Collection
    {
        $ids = collect([
            $this->trip_type_id, $this->group_type_id, $this->persona_id,
            $this->country_region_id, $this->city_id, $this->budget_tier_id, $this->tip_smestaja_id,
        ])->filter();

        $tagSlugs = collect($this->free_text_answers['preference_tags'] ?? [])
            ->merge($this->free_text_answers['implied_preference_tags'] ?? []);
        $tagIds = TaxonomyNode::where('type', 'preference_tag')->whereIn('slug', $tagSlugs)->pluck('id');

        $ids = $ids->merge($tagIds);

        // Bug fixed 2026-08-06 (owner-caught): "Foodie" worked differently depending on group
        // size — the solo `persona` question sets the `persona_id` FK (already covered above),
        // but the group `persona_group` question stores the SAME taxonomy type as bare slugs in
        // free_text_answers.persona_tags, same shape as preference_tags. Without this, any
        // implies/suggests/excludes wired FROM a persona node (e.g. gurman -> dobra_hrana) only
        // ever fired for solo travelers — groups picking the identical persona got nothing.
        $personaTagIds = TaxonomyNode::where('type', 'persona')
            ->whereIn('slug', collect($this->free_text_answers['persona_tags'] ?? []))
            ->pluck('id');

        $ids = $ids->merge($personaTagIds);

        // termin_category is the one taxonomy-linked field stored as a bare slug string, not an
        // `_id` FK (see the search_sessions migration) — was missing here entirely, which meant
        // any implies/excludes edge authored FROM a termin_category node (e.g. a themed entry
        // point like "kasno_kupanje" excluding non-swim geography) silently never applied,
        // since this node never showed up as "selected" for GeographyResolver to check. Bug
        // caught 2026-07-14 while building the first themed-entry-point content.
        if ($this->termin_category) {
            $terminCategoryId = TaxonomyNode::where('type', 'termin_category')
                ->where('slug', $this->termin_category)
                ->value('id');

            if ($terminCategoryId) {
                $ids->push($terminCategoryId);
            }
        }

        return $ids->unique()->values();
    }
}
