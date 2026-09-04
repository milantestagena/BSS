<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Capture-only, no send pipeline yet — see NewsletterResolver::subscribe. Owner's ask,
 * 2026-09-04: "subskribujte, da vam javimo kad imamo sledecu lepu ponudu" — the About page's
 * subscribe form writes here; actually emailing this list is a real future feature.
 */
class NewsletterSubscriber extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'email',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
