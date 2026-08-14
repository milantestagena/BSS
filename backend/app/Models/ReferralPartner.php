<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A reseller profile linking an EXISTING customer User to the influencer program — see
// CLAUDE.md section 6. Created only by an admin promoting a user (UserResource's "Make
// reseller" action), never self-signup. Logs in the same "Sign in with Google" way as any
// other customer (see PartnerDashboardController) — there is no separate password/guard.
#[Fillable(['user_id', 'share_percentage', 'decay_enabled', 'status', 'notes'])]
class ReferralPartner extends Model
{
    protected function casts(): array
    {
        return [
            'share_percentage' => 'decimal:2',
            'decay_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referralCodes(): HasMany
    {
        return $this->hasMany(ReferralCode::class);
    }

    public function referralAttributions(): HasMany
    {
        return $this->hasMany(ReferralAttribution::class);
    }
}
