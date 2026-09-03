<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class TaxonomyNode extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $fillable = [
        'parent_id',
        'booking_location_id',
        'type',
        'slug',
        'label',
        'sort_order',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(TaxonomyNode::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * The matched Booking.com location for this node (country/city), if any — see Location and
     * the create_locations_table migration for why this is a separate table + a nullable link,
     * not a merge. Most nodes won't have one until real Booking IDs are known.
     */
    public function bookingLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Nodes this node implies (e.g. persona "Gurman" -> preference_tag "dobra_hrana").
     * Selecting this node silently records the implied node as an answer elsewhere AND
     * hides the implied node from being offered as its own separate choice.
     */
    public function implies(): BelongsToMany
    {
        return $this->belongsToMany(
            TaxonomyNode::class,
            'taxonomy_node_relations',
            'from_taxonomy_node_id',
            'to_taxonomy_node_id'
        )->wherePivot('relation_type', 'implies');
    }

    /**
     * Nodes this node suggests (e.g. preference_tag "jeftino" -> budget_tier "do_20e").
     * Selecting this node pre-fills the suggested node as a visible, editable follow-up
     * answer — unlike `implies`, the user still sees and can override it.
     */
    public function suggests(): BelongsToMany
    {
        return $this->belongsToMany(
            TaxonomyNode::class,
            'taxonomy_node_relations',
            'from_taxonomy_node_id',
            'to_taxonomy_node_id'
        )->wherePivot('relation_type', 'suggests');
    }

    /**
     * Nodes this node excludes (e.g. trip_type "City break" -> termin_category "Letovanje").
     * Directional — see migration comment. Selecting this node removes the excluded node(s)
     * from whatever question offers them.
     */
    public function excludes(): BelongsToMany
    {
        return $this->belongsToMany(
            TaxonomyNode::class,
            'taxonomy_node_relations',
            'from_taxonomy_node_id',
            'to_taxonomy_node_id'
        )->wherePivot('relation_type', 'excludes');
    }

    /**
     * meal_plan nodes this country/city really offers (from = destination, to = meal_plan) —
     * same directed-edge shape as implies/excludes, not a new table. Owner's ask, 2026-08-31,
     * after a real Booking capture showed Turkey offers Breakfast/Breakfast&dinner/All-inclusive/
     * Self catering but NOT "Breakfast & lunch" or "All meals included" — a session picking one
     * of those two was passing budget-fit for a combination that isn't realistically bookable
     * there. NOT yet consumed by GeographyResolver's filtering/ranking — how a mismatch should
     * behave (exclude vs. downrank) is still an open decision, this is the data layer only.
     */
    public function offersMealPlan(): BelongsToMany
    {
        return $this->belongsToMany(
            TaxonomyNode::class,
            'taxonomy_node_relations',
            'from_taxonomy_node_id',
            'to_taxonomy_node_id'
        )->wherePivot('relation_type', 'offers_meal_plan');
    }

    /**
     * Real, researched meal-plan slugs for this destination, falling back to the parent when
     * this node has NO edges of its own at all — same "not yet researched" vs. "researched, and
     * genuinely offers none of these" distinction as climateFor()/culturalTierFor(). A city with
     * zero of its own edges inherits its country's set rather than silently offering nothing.
     */
    public function offeredMealPlanSlugs(): Collection
    {
        $own = $this->offersMealPlan()->pluck('slug');

        return $own->isNotEmpty() ? $own : ($this->parent?->offeredMealPlanSlugs() ?? collect());
    }

    public function offersMealPlanSlug(string $mealPlanSlug): bool
    {
        return $this->offeredMealPlanSlugs()->contains($mealPlanSlug);
    }

    /**
     * Inverse of implies/suggests/excludes — "what points at me". Read-only in the admin
     * (see TaxonomyNodeResource's ReferencedByRelationManager): lets an admin open any node
     * and see both directions at a glance, since these relations are intentionally not
     * auto-symmetric (see migration comment / wizard_architecture).
     */
    public function impliedBy(): BelongsToMany
    {
        return $this->belongsToMany(
            TaxonomyNode::class,
            'taxonomy_node_relations',
            'to_taxonomy_node_id',
            'from_taxonomy_node_id'
        )->wherePivot('relation_type', 'implies');
    }

    public function suggestedBy(): BelongsToMany
    {
        return $this->belongsToMany(
            TaxonomyNode::class,
            'taxonomy_node_relations',
            'to_taxonomy_node_id',
            'from_taxonomy_node_id'
        )->wherePivot('relation_type', 'suggests');
    }

    public function excludedBy(): BelongsToMany
    {
        return $this->belongsToMany(
            TaxonomyNode::class,
            'taxonomy_node_relations',
            'to_taxonomy_node_id',
            'from_taxonomy_node_id'
        )->wherePivot('relation_type', 'excludes');
    }

    /**
     * All incoming edges (implies + suggests + excludes combined) as raw pivot rows, each
     * with its `from` node and `relation_type` — backs the read-only "Referenced by" tab,
     * which needs to show all three relation types together in one list.
     */
    public function referencedBy(): HasMany
    {
        return $this->hasMany(TaxonomyNodeRelation::class, 'to_taxonomy_node_id');
    }

    /**
     * Termin categories (letovanje/zimovanje/...) this node (a location — country or city) has
     * a seasonal window for, e.g. Greece -> letovanje. Unlike implies/suggests/excludes, this
     * edge carries a payload (`meta.months`), since which months a location is good for a given
     * termin_category is a property of the PAIR, not a global tag on either node — see
     * wizard_architecture's Patagonia counter-example (a country can contain both ski and beach
     * destinations). Not consumed by GeographyResolver's filtering — this is caveat/scoring
     * data for the not-yet-built results stage (e.g. "December beach trip, nearest is Hurghada,
     * but it's a bit cool"), not a hard exclude.
     */
    public function seasonalWindows(): BelongsToMany
    {
        return $this->belongsToMany(
            TaxonomyNode::class,
            'taxonomy_node_relations',
            'from_taxonomy_node_id',
            'to_taxonomy_node_id'
        )->wherePivot('relation_type', 'seasonal_window')->withPivot('meta');
    }

    /**
     * The seasonal_window payload (e.g. ['months' => [6,7,8,9]]) for this location + a given
     * termin_category, or null if no window is defined for that pair. Reads straight off
     * TaxonomyNodeRelation (which casts `meta` to array) rather than the seasonalWindows()
     * belongsToMany above, since Eloquent pivot attributes don't auto-cast jsonb.
     */
    public function seasonalWindowFor(TaxonomyNode $terminCategory): ?array
    {
        return TaxonomyNodeRelation::where('from_taxonomy_node_id', $this->id)
            ->where('to_taxonomy_node_id', $terminCategory->id)
            ->where('relation_type', 'seasonal_window')
            ->first()
            ?->meta;
    }

    /**
     * cost_category nodes (hospitality/local_stores/transport) this node cares about, e.g.
     * persona "Gurman" -> cost_category "hospitality". Carries a `meta.weight` payload (1-3,
     * same scale as the not-yet-built WizardQuestion.importance_weight — one weighting
     * convention app-wide). Not limited to persona: any taxonomy node (preference_tag,
     * tip_smestaja, ...) can be the `from` side. Aggregation rule across multiple selected
     * nodes pointing at the same cost_category is MAX, not SUM (see wizard_architecture) —
     * not yet enforced in code since there's no consumer (results/ranking stage) yet.
     */
    public function weightedToward(): BelongsToMany
    {
        return $this->belongsToMany(
            TaxonomyNode::class,
            'taxonomy_node_relations',
            'from_taxonomy_node_id',
            'to_taxonomy_node_id'
        )->wherePivot('relation_type', 'weighted_toward')->withPivot('meta');
    }

    /**
     * The weight (1-3) this node assigns to a given cost_category, or null if no edge exists
     * (= not relevant, not zero-but-tracked). See weightedToward() for the aggregation caveat.
     */
    public function weightToward(TaxonomyNode $costCategory): ?int
    {
        $relation = TaxonomyNodeRelation::where('from_taxonomy_node_id', $this->id)
            ->where('to_taxonomy_node_id', $costCategory->id)
            ->where('relation_type', 'weighted_toward')
            ->first();

        return $relation?->meta['weight'] ?? null;
    }

    /**
     * Monthly climate rows for this location (country/city) — see TaxonomyNodeClimate.
     */
    public function climateMonths(): HasMany
    {
        return $this->hasMany(TaxonomyNodeClimate::class)->orderBy('month');
    }

    /**
     * Climate for a specific month (1-12), falling back to the parent node (city -> country) if
     * this node has no climate rows seeded yet — same fallback pattern GeographyResolver already
     * uses for other meta tags via the parent chain. Returns null only if neither this node nor
     * any ancestor has that month seeded.
     */
    public function climateFor(int $month): ?TaxonomyNodeClimate
    {
        return $this->climateMonths()->where('month', $month)->first()
            ?? $this->parent?->climateFor($month);
    }

    /**
     * meta.vibe_profile.description as a real Eloquent attribute — exists ONLY so
     * HasTranslations::translate('vibe_profile_description', $locale) can hash/compare it
     * exactly like it already does for `label` (that method reads $this->{$field} directly, so
     * a real accessor here is required, not just the raw meta array access wizard.ts's old
     * client-side reader used). English canonical, same as label. Null if this node has no
     * vibe_profile description at all.
     */
    public function getVibeProfileDescriptionAttribute(): ?string
    {
        return $this->meta['vibe_profile']['description'] ?? null;
    }

    /**
     * GraphQL-facing wrapper for @method — see vibeDescription field in schema.graphql.
     * Bug fixed 2026-09-03 (owner caught it live, German UI): every card's "See more" popover
     * showed this text in English regardless of locale — vibe_profile.description lived only
     * inside the generic `meta: JSON` blob, which @translate can't reach (it operates on one
     * resolved GraphQL field, not a path inside a JSON value). Real dedicated field + @translate
     * combo instead, same mechanism as `label`.
     */
    public function vibeDescription(): ?string
    {
        return $this->vibe_profile_description;
    }

    /**
     * Cultural availability rows (alcohol/pork/halal/vegan/organic/cannabis/dress_code/
     * lgbtq_friendly/tap_water) for this location — see CulturalAvailability, 2026-07-30.
     */
    public function culturalAvailability(): HasMany
    {
        return $this->hasMany(CulturalAvailability::class);
    }

    /**
     * Tier (1-4, 1=most freely available) for a given category, falling back to the parent
     * node (city -> country) if this node has no row for it yet — same fallback pattern as
     * climateFor(). Seeded primarily at country level; a specific resort town can override
     * with its own row later if it genuinely differs from its country's general pattern.
     */
    public function culturalTierFor(string $category): ?CulturalAvailability
    {
        return $this->culturalAvailability()->where('category', $category)->first()
            ?? $this->parent?->culturalTierFor($category);
    }

    /**
     * Monthly accommodation season-tier rows for this location — see
     * TaxonomyNodeAccommodationSeason, 2026-08-03.
     */
    public function accommodationSeasons(): HasMany
    {
        return $this->hasMany(TaxonomyNodeAccommodationSeason::class);
    }

    /**
     * Season tier (van_sezone/pred_post_sezona/sezona) for a given month, falling back to the
     * parent node — same fallback pattern as climateFor()/culturalTierFor().
     */
    public function accommodationSeasonTierFor(int $month): ?TaxonomyNodeAccommodationSeason
    {
        return $this->accommodationSeasons()->where('month', $month)->first()
            ?? $this->parent?->accommodationSeasonTierFor($month);
    }

    /**
     * Real, manually-observed nightly prices for this location — see
     * LateSummerAccommodationPrice, 2026-08-03 (owner's own name/process for this data).
     */
    public function lateSummerPrices(): HasMany
    {
        return $this->hasMany(LateSummerAccommodationPrice::class);
    }

    /**
     * A real observed price for the given tier, falling back to the parent node — same
     * pattern as climateFor()/culturalTierFor()/accommodationSeasonTierFor(). Returns null if
     * neither this node nor any ancestor has been manually surveyed for that tier yet — the
     * caller (AccommodationPriceEstimator) then falls back to the global multiplier estimate.
     */
    public function lateSummerPriceFor(string $tier): ?LateSummerAccommodationPrice
    {
        return $this->lateSummerPrices()->where('season_tier', $tier)->first()
            ?? $this->parent?->lateSummerPriceFor($tier);
    }

    /**
     * Real per-(campaign, destination) accommodation prices — see
     * WizardCampaignDestinationPrice, 2026-08-05.
     */
    public function campaignDestinationPrices(): HasMany
    {
        return $this->hasMany(WizardCampaignDestinationPrice::class);
    }

    /**
     * The priced row for a given campaign, falling back to the parent node — same pattern as
     * climateFor()/culturalTierFor()/lateSummerPriceFor() (returns the MODEL, not a raw
     * scalar, so callers can also read `includes_meals` — see the Egypt all-inclusive catch,
     * 2026-08-05: Hurghada/Sharm El Sheikh are almost always all-inclusive, so their price
     * already bundles food and BudgetEstimationEngine's separate food estimate must be
     * skipped for them; Marsa Alam has a real town and normal non-all-inclusive stays, so it
     * stays `false`). Owner's call, 2026-08-05: campaign-scoped (not seasonal-tiered) pricing
     * for now, since a campaign like "kasno-letovanje" targets one narrow window that doesn't
     * cleanly map to van_sezone/sezona/praznici — "cene nisu ni sezonske ni van sezonske."
     * Null if neither this node nor any ancestor has a price entered yet for this campaign
     * (see campaign:seed-destination-price-rows — rows are pre-created empty, so null here
     * means "not filled in yet," not "no row exists").
     */
    public function campaignPriceFor(int $wizardCampaignId): ?WizardCampaignDestinationPrice
    {
        $row = $this->campaignDestinationPrices()
            ->where('wizard_campaign_id', $wizardCampaignId)
            ->whereNotNull('price_per_person_eur')
            ->first();

        return $row ?? $this->parent?->campaignPriceFor($wizardCampaignId);
    }

    /**
     * Optional deep-dive guide content per (campaign, destination) — see DestinationGuide,
     * 2026-08-19. Unlike campaignPriceFor() above, deliberately NO parent-chain fallback: a
     * city never silently inherits its country's guide (or vice versa) — each is independently
     * authored, since a country's multi-stop itinerary has nothing meaningful to say about one
     * specific resort town within it, and a city's costs+tips aren't a substitute for the
     * country's broader guide either.
     */
    public function guides(): HasMany
    {
        return $this->hasMany(DestinationGuide::class);
    }

    public function guideFor(int $wizardCampaignId): ?DestinationGuide
    {
        return $this->guides()->where('wizard_campaign_id', $wizardCampaignId)->first();
    }

    /**
     * meta.hospitality (avg_restaurant_meal_eur, avg_cafe_coffee_eur — see
     * BudgetEstimationEngine) for this node, falling back to the parent node (city -> country)
     * if this node has no hospitality meta of its own — same fallback pattern as
     * climateFor()/culturalTierFor(). Needed for city-level budget-fit filtering (2026-09-01):
     * hospitality is seeded on country nodes only, so a city's own meta is always empty and
     * would otherwise make every city-level budget estimate come back null.
     */
    public function hospitalityMeta(): ?array
    {
        return $this->meta['hospitality'] ?? $this->parent?->hospitalityMeta();
    }

    /**
     * Great-circle distance in km to another geography node, via `meta.lat`/`meta.lng` on both
     * sides (convention, not a schema column — see wizard_architecture's distance-as-its-own-
     * wizard-step decision). Returns null if either node is missing coordinates, so callers
     * decide how to degrade (hide the distance, omit it from ranking, ...) rather than this
     * method guessing.
     */
    public function distanceKmTo(TaxonomyNode $other): ?float
    {
        $lat1 = $this->meta['lat'] ?? null;
        $lng1 = $this->meta['lng'] ?? null;
        $lat2 = $other->meta['lat'] ?? null;
        $lng2 = $other->meta['lng'] ?? null;

        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
            return null;
        }

        $earthRadiusKm = 6371;

        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
