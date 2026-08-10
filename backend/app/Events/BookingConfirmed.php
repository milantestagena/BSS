<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * See CLAUDE.md section 5 — fired once a booking is confirmed (via Booking.com postback or
 * manual verification), which credits the user +20. Not wired to a real trigger yet, 2026-08-10
 * — there's no postback endpoint until real Booking.com affiliate access exists (application
 * pending). Scaffolded now so the credit-side of the flow is ready the moment that trigger
 * exists; nothing here needs to change when it does.
 */
class BookingConfirmed
{
    use Dispatchable;

    public function __construct(public User $user, public ?string $bookingReference = null)
    {
    }
}
