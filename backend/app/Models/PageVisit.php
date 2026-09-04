<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Raw daily-visit count with location — owner's ask, 2026-09-04. Deliberately holds no IP
 * address (see PageVisitResolver::record) — country/city only, resolved once at write time via
 * the same IpGeolocationClient WorldCityResolver::detectHomeCity already uses.
 */
class PageVisit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'path',
        'country',
        'city',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
