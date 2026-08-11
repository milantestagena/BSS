<?php

namespace App\Listeners;

use App\Events\BookingConfirmed;

/**
 * User-to-user CREDIT referral — CLAUDE.md section 3/6, owner's ask 2026-08-11. Deliberately
 * separate from GenerateCommissionShare (the influencer/money system): no new tables, just
 * credits the referrer's own wallet whenever the user they referred gets a confirmed booking.
 * Cumulative like GrantBookingCredits — every confirmed booking earns the referrer another 10,
 * not just the first (same uncapped philosophy as CLAUDE.md section 5's own +20 rule).
 */
class GrantReferralCredits
{
    private const REFERRAL_BONUS_CREDITS = 10;

    public function handle(BookingConfirmed $event): void
    {
        $referrer = $event->user->referredBy;

        if (! $referrer) {
            return;
        }

        $referrer->wallet()->increment('balance', self::REFERRAL_BONUS_CREDITS);
        $referrer->creditTransactions()->create([
            'amount' => self::REFERRAL_BONUS_CREDITS,
            'type' => 'referral',
            'description' => $event->bookingReference
                ? "Referred user booked ({$event->bookingReference})"
                : 'Referred user booked',
        ]);
    }
}
