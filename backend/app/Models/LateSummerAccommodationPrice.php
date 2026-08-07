<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LateSummerAccommodationPrice extends Model
{
    protected $fillable = [
        'taxonomy_node_id',
        'season_tier',
        'price_per_night_eur',
        'notes',
        'observed_at',
        'source',
    ];

    protected $casts = [
        'price_per_night_eur' => 'float',
        'observed_at' => 'date',
    ];

    public function taxonomyNode(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class);
    }
}
