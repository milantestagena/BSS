<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// First-touch, permanently locked — created once per user and never updated. See CLAUDE.md
// section 6 and the create_referral_attributions_table migration.
#[Fillable(['user_id', 'referral_partner_id', 'referral_code_id', 'ip_address', 'flagged_for_review'])]
class ReferralAttribution extends Model
{
    protected function casts(): array
    {
        return [
            'flagged_for_review' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(ReferralPartner::class, 'referral_partner_id');
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class, 'referral_code_id');
    }

    public function commissionShares(): HasMany
    {
        return $this->hasMany(CommissionShare::class);
    }
}
