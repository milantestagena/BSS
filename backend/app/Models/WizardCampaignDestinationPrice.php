<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WizardCampaignDestinationPrice extends Model
{
    protected $fillable = [
        'wizard_campaign_id',
        'taxonomy_node_id',
        'price_per_person_eur',
        'includes_meals',
        'notes',
        'source',
    ];

    protected $casts = [
        'price_per_person_eur' => 'float',
        'includes_meals' => 'boolean',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WizardCampaign::class, 'wizard_campaign_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'taxonomy_node_id');
    }
}
