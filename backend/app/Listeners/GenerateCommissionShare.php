<?php

namespace App\Listeners;

use App\Events\BookingConfirmed;
use App\Models\CommissionShare;
use App\Models\ReferralAttribution;

/**
 * See CLAUDE.md section 6 — one CommissionShare per confirmed booking, for users who came in
 * through a referral partner's code. No-op if the user has no attribution (the common case
 * today, since no partner codes are live yet). `estimated_amount_eur` is left null here —
 * BookingConfirmed doesn't carry a booking amount yet, and guessing one would be fabricating
 * data; it gets filled in later via the monthly Partner Centre CSV reconciliation (admin,
 * manual) once that data actually exists.
 */
class GenerateCommissionShare
{
    public function handle(BookingConfirmed $event): void
    {
        $attribution = ReferralAttribution::where('user_id', $event->user->id)->first();

        if (! $attribution) {
            return;
        }

        $sequenceNumber = $attribution->commissionShares()->count() + 1;

        $attribution->commissionShares()->create([
            'booking_reference' => $event->bookingReference,
            'booking_sequence_number' => $sequenceNumber,
            'share_percentage_applied' => CommissionShare::decayTierPercentage($attribution->partner, $sequenceNumber),
            'status' => 'pending',
        ]);
    }
}
