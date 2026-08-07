<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxonomyNodeAccommodationSeason extends Model
{
    protected $fillable = [
        'taxonomy_node_id',
        'month',
        'season_tier',
        'source',
    ];

    protected $casts = [
        'month' => 'integer',
    ];

    public function taxonomyNode(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class);
    }
}
