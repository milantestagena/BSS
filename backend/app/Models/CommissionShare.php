<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One row per confirmed booking traced to a referral — see CLAUDE.md section 6 and the
// create_commission_shares_table migration.
#[Fillable([
    'referral_attribution_id', 'booking_reference', 'booking_sequence_number',
    'share_percentage_applied', 'estimated_amount_eur', 'status', 'reconciled_at', 'paid_at',
])]
class CommissionShare extends Model
{
    protected function casts(): array
    {
        return [
            'share_percentage_applied' => 'decimal:2',
            'estimated_amount_eur' => 'decimal:2',
            'reconciled_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function attribution(): BelongsTo
    {
        return $this->belongsTo(ReferralAttribution::class, 'referral_attribution_id');
    }

    /**
     * Decay-tier percentage for the Nth confirmed booking by the referred user — see CLAUDE.md
     * section 6. Only the FIRST booking's rate is the partner's own negotiated
     * `share_percentage` (e.g. standard 50%, or up to 100% for the launch-incentive partner);
     * the 2nd/3rd tiers are fixed standard values regardless of that negotiated rate, and the
     * 4th booking onward earns nothing.
     *
     * `decay_enabled = false` (owner's ask, 2026-08-14 — a micro-influencer blogger cohort)
     * skips all of that: every booking, forever, pays the partner's own `share_percentage`
     * flat. Owner's own reasoning: "ako mnogo dobrih dovede, mnogo mi je doneo prihod" — a
     * partner earning more because they brought a lot of genuinely good referrals isn't a risk
     * to cap, it scales with revenue they actually generated. Defaults to true (existing decay
     * curve, unchanged) for every partner that hasn't opted into the flat rate.
     */
    public static function decayTierPercentage(ReferralPartner $partner, int $bookingSequenceNumber): float
    {
        if (! $partner->decay_enabled) {
            return (float) $partner->share_percentage;
        }

        return match ($bookingSequenceNumber) {
            1 => (float) $partner->share_percentage,
            2 => 15.0,
            3 => 5.0,
            default => 0.0,
        };
    }
}
