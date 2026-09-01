<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class WizardCampaign extends Model
{
    use HasTranslations;

    protected $fillable = [
        'key',
        'label',
        'landing_headline',
        'preset_answers',
        'is_active',
        'sort_order',
        'season_start_date',
        'season_end_date',
        'meta',
    ];

    protected $casts = [
        'preset_answers' => 'array',
        'is_active' => 'boolean',
        'season_start_date' => 'date',
        'season_end_date' => 'date',
        'meta' => 'array',
    ];

    /**
     * The subset + order of global wizard_questions this campaign asks — same underlying
     * questions/mappings as every other campaign, just a different selection/sort_order.
     * `withPivot('sort_order')` since ordering is per-campaign, not the question's own global
     * sort_order.
     */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(WizardQuestion::class, 'wizard_campaign_questions')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * Real per-(campaign, destination) accommodation prices — see
     * WizardCampaignDestinationPrice, 2026-08-05. Campaign-scoped rather than seasonal-tiered
     * on purpose: this campaign targets one narrow window, not a full year.
     */
    public function destinationPrices(): HasMany
    {
        return $this->hasMany(WizardCampaignDestinationPrice::class);
    }

    /**
     * Every Saturday-aligned week_start_date between season_start_date and season_end_date —
     * owner's ask, 2026-08-11: "podelimo na nedelje... neko krene od subote i vrati se iduce
     * nedelje". Drives both the weekly price seeder command and the Filament week filter.
     * Empty if either date is unset (no season configured for this campaign yet).
     */
    public function seasonWeeks(): Collection
    {
        if (! $this->season_start_date || ! $this->season_end_date) {
            return collect();
        }

        $weeks = collect();
        $cursor = CarbonImmutable::instance($this->season_start_date);
        $end = CarbonImmutable::instance($this->season_end_date);

        while ($cursor->lt($end)) {
            $weeks->push($cursor);
            $cursor = $cursor->addDays(7);
        }

        return $weeks;
    }

    /**
     * The trip-length day-count implied by this campaign's preset termin_category answer, if it
     * has one — owner's ask, 2026-09-01, for a realistic total_budget default (adults/children
     * daily rate × trip length, see wizard.ts's syncDefaultBudget()). termin_category is never a
     * rendered question when a campaign presets it (see seedWizardCampaigns's $questionKeys,
     * which omits it entirely), so the frontend never loads that taxonomy node's options the way
     * it does for the generic flow — this exposes the same default_duration_days meta the
     * generic flow would read client-side, resolved server-side instead. Null if this campaign
     * doesn't preset termin_category, or that node has no default_duration_days meta.
     */
    public function presetTripLengthDays(): ?int
    {
        $slug = $this->preset_answers['termin_category'] ?? null;
        if (! $slug) {
            return null;
        }

        return TaxonomyNode::where('type', 'termin_category')->where('slug', $slug)->first()?->meta['default_duration_days'] ?? null;
    }
}
