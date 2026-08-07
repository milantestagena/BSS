<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CulturalAvailability extends Model
{
    protected $table = 'cultural_availability';

    protected $fillable = [
        'taxonomy_node_id',
        'category',
        'tier',
        'label',
        'source',
    ];

    protected $casts = [
        'tier' => 'integer',
    ];

    public function taxonomyNode(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class);
    }
}
