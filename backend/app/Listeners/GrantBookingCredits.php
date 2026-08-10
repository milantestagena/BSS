<?php

namespace App\Listeners;

use App\Events\BookingConfirmed;

/** See CLAUDE.md section 5 — +20 credits per confirmed booking, cumulative (no cap). */
class GrantBookingCredits
{
    private const BOOKING_BONUS_CREDITS = 20;

    public function handle(BookingConfirmed $event): void
    {
        $event->user->wallet()->increment('balance', self::BOOKING_BONUS_CREDITS);
        $event->user->creditTransactions()->create([
            'amount' => self::BOOKING_BONUS_CREDITS,
            'type' => 'booking',
            'description' => $event->bookingReference
                ? "Confirmed booking ({$event->bookingReference})"
                : 'Confirmed booking',
        ]);
    }
}
