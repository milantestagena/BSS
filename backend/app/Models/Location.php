<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Booking.com's raw location catalog (or a fake-ID test stand-in for it, see `source`), kept
 * deliberately separate from TaxonomyNode — see the create_locations_table migration comment.
 */
class Location extends Model
{
    protected $fillable = [
        'booking_dest_id',
        'dest_type',
        'name',
        'country_code',
        'nr_hotels',
        'source',
        'imported_at',
    ];

    protected $casts = [
        'imported_at' => 'datetime',
    ];

    public function taxonomyNodes(): HasMany
    {
        return $this->hasMany(TaxonomyNode::class, 'booking_location_id');
    }
}
