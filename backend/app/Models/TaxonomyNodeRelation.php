<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * First-class model for the taxonomy_node_relations pivot table — used where we need to
 * read/display the raw edges directly (e.g. TaxonomyNodeResource's read-only "Referenced by"
 * tab, which shows implies/suggests/excludes together). The BelongsToMany relations on
 * TaxonomyNode itself (implies/suggests/excludes/impliedBy/...) remain the normal way to
 * query "what does this node imply" etc. — this model exists alongside them, not instead.
 */
class TaxonomyNodeRelation extends Model
{
    protected $table = 'taxonomy_node_relations';

    protected $fillable = [
        'from_taxonomy_node_id',
        'to_taxonomy_node_id',
        'relation_type',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function from(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'from_taxonomy_node_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(TaxonomyNode::class, 'to_taxonomy_node_id');
    }
}
