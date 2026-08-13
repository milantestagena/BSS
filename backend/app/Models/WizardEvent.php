<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Raw funnel/usage log — owner's ask, 2026-08-13. Deliberately dumb: no aggregation, no derived
 * fields, just "what happened, when, for which session" so a real report can be built once
 * there's actual traffic to look at (event_type examples: step_viewed, zero_match_fallback,
 * results_reached — see recordWizardEvent mutation and GeographyResolver's fallback branch).
 */
class WizardEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'search_session_id',
        'event_type',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];
}
