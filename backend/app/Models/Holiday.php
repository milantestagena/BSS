<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    use HasFactory;

    protected $fillable = [
        'country_taxonomy_node_id',
        'name',
        'date',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'country_taxonomy_node_id');
    }
}
