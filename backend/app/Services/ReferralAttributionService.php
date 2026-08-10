<?php

namespace App\Services;

use App\Models\ReferralAttribution;
use App\Models\ReferralCode;
use App\Models\User;

/**
 * First-touch referral attribution — see CLAUDE.md section 6. Called once, at the moment a
 * brand-new User row is created (Google OAuth callback today; any future signup path later).
 * Never called again for that user afterward, so attribution is permanently locked by
 * construction (there is no update path, only create).
 */
class ReferralAttributionService
{
    /**
     * Signups from the same IP within this window are flagged for manual review, not blocked —
     * a shared IP (office wifi, family) isn't proof of abuse on its own. See CLAUDE.md section 6.
     */
    private const VELOCITY_WINDOW_MINUTES = 60;
    private const VELOCITY_THRESHOLD = 5;

    /**
     * Records an attribution for a new user if `$refCode` matches a real ReferralCode. No-op
     * (returns null) if the code doesn't exist, or if it belongs to the partner's own account
     * (self-referral block — a partner cannot be attributed on their own code).
     */
    public function attribute(User $user, ?string $refCode, ?string $ipAddress): ?ReferralAttribution
    {
        if (! $refCode) {
            return null;
        }

        // First-touch is meant to be locked permanently — guard against a second call for the
        // same user (e.g. a retried request) hitting the unique constraint instead of just
        // being a no-op, since the first attribution already won.
        if (ReferralAttribution::where('user_id', $user->id)->exists()) {
            return null;
        }

        $code = ReferralCode::with('partner')->where('code', $refCode)->first();

        if (! $code) {
            return null;
        }

        if ($code->partner->email === $user->email) {
            return null;
        }

        $recentFromSameIp = $ipAddress
            ? ReferralAttribution::where('ip_address', $ipAddress)
                ->where('created_at', '>=', now()->subMinutes(self::VELOCITY_WINDOW_MINUTES))
                ->count()
            : 0;

        return ReferralAttribution::create([
            'user_id' => $user->id,
            'referral_partner_id' => $code->referral_partner_id,
            'referral_code_id' => $code->id,
            'ip_address' => $ipAddress,
            'flagged_for_review' => $recentFromSameIp >= self::VELOCITY_THRESHOLD,
        ]);
    }
}
