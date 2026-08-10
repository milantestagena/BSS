<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/** Welcome-bonus credit amount granted on first creation — see CLAUDE.md section 5. Kept as a
 *  constant here (not config) matching this codebase's "constants for anything that doesn't
 *  need per-campaign tuning yet" convention (see BudgetEstimationEngine). */
const WELCOME_BONUS_CREDITS = 5;

#[Fillable(['name', 'email', 'password', 'google_id', 'avatar_url', 'referral_source'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
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
