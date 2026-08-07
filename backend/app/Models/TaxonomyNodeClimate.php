<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxonomyNodeClimate extends Model
{
    protected $table = 'taxonomy_node_climates';

    protected $fillable = [
        'taxonomy_node_id',
        'month',
        'avg_temp_c',
        'sea_temp_c',
        'precip_mm',
        'sun_hours',
        'snow_cm',
        'source',
    ];

    protected $casts = [
        'avg_temp_c' => 'float',
        'sea_temp_c' => 'float',
        'precip_mm' => 'float',
        'sun_hours' => 'float',
        'snow_cm' => 'float',
    ];

    public function taxonomyNode(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class);
    }
}
