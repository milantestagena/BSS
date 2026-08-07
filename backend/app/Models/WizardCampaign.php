<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WizardCampaign extends Model
{
    protected $fillable = [
        'key',
        'label',
        'landing_headline',
        'preset_answers',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'preset_answers' => 'array',
        'is_active' => 'boolean',
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
}
