<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/** Welcome-bonus credit amount granted on first creation — see CLAUDE.md section 5. Kept as a
 *  constant here (not config) matching this codebase's "constants for anything that doesn't
 *  need per-campaign tuning yet" convention (see BudgetEstimationEngine). */
const WELCOME_BONUS_CREDITS = 5;

#[Fillable(['name', 'email', 'password', 'google_id', 'avatar_url', 'referral_source', 'referred_by_user_id', 'is_admin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /** Gates the Filament /admin panel — see the add_is_admin_to_users_table migration. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin;
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /** The referral partner (reseller) profile for this user, if an admin has promoted them —
     *  see CLAUDE.md section 6 and ReferralPartner. Most users never have one. */
    public function resellerProfile(): HasOne
    {
        return $this->hasOne(ReferralPartner::class);
    }

    /** The first-touch referral attribution for this user, if they signed up through a
     *  partner's code — see ReferralAttributionService. Null for organic signups. */
    public function referralAttribution(): HasOne
    {
        return $this->hasOne(ReferralAttribution::class);
    }

    /**
     * User-to-user CREDIT referral (CLAUDE.md section 3/6) — deliberately separate from the
     * money-based ReferralPartner system above. Every user is implicitly their own referrer via
     * a `?ref=u<id>` link (see GoogleAuthController), so this needs no extra table: just who
     * referred THIS user, set once at signup and never overwritten (first-touch).
     */
    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    /** This user's own shareable link — every logged-in user has one automatically, no
     *  admin promotion needed (unlike the reseller/ReferralPartner program). */
    public function referralCode(): string
    {
        return 'u' . $this->id;
    }

    /**
     * Every user gets a Wallet + welcome-bonus CreditTransaction the moment the row is
     * created — regardless of which path created it (Google OAuth callback today, Filament
     * admin creation, factories in tests, ...), so this can never be forgotten in one flow but
     * not another. See CLAUDE.md section 5.
     */
    protected static function booted(): void
    {
        static::created(function (User $user) {
            $user->wallet()->create(['balance' => WELCOME_BONUS_CREDITS]);
            $user->creditTransactions()->create([
                'amount' => WELCOME_BONUS_CREDITS,
                'type' => 'welcome',
                'description' => 'Welcome bonus',
            ]);
        });
    }
}
