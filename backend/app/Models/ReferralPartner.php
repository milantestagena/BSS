<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

// Influencer/blogger partner — logs in via its own 'partner' guard (see config/auth.php),
// separate from customer Google OAuth. Manually onboarded by admin, not self-signup — see
// CLAUDE.md section 6.
#[Fillable(['name', 'email', 'password', 'share_percentage', 'status', 'notes'])]
#[Hidden(['password', 'remember_token'])]
class ReferralPartner extends Authenticatable
{
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'share_percentage' => 'decimal:2',
        ];
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
