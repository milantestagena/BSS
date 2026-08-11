<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ReferralAttributionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

/**
 * Google OAuth login — see CLAUDE.md section 5/8. Full-page redirect flow (not an AJAX/SPA
 * popup): the Angular app sends the browser here directly, Google redirects back to
 * /auth/google/callback, and we redirect the browser back into the app once logged in. This
 * works cleanly because frontend and backend share one origin behind nginx (see
 * wizard_architecture, 2026-08-07 deploy notes) — no CORS/cross-site cookie complications.
 *
 * `referralSource` (?ref=xyz on the redirect URL) is captured and stored on first login only —
 * see CLAUDE.md section 6, first-touch attribution is meant to be permanently locked, not
 * overwritten on a later visit with a different ref.
 */
class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        $driver = Socialite::driver('google');
        if ($ref = request('ref')) {
            session(['pending_referral_source' => $ref]);
        }

        return $driver->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::firstWhere('google_id', $googleUser->getId())
            ?? User::firstWhere('email', $googleUser->getEmail());

        if ($user) {
            if (! $user->google_id) {
                $user->update(['google_id' => $googleUser->getId(), 'avatar_url' => $googleUser->getAvatar()]);
            }
        } else {
            $refSource = session('pending_referral_source');

            // User-to-user CREDIT referral (CLAUDE.md section 3/6, distinct from the
            // influencer/money ReferralAttribution below): every user's own share link is
            // `?ref=u<id>` (see User::referralCode()) — checked FIRST since it's a cheap format
            // match, no DB hit unless it actually looks like one.
            $referredByUserId = null;
            if ($refSource && preg_match('/^u(\d+)$/', $refSource, $m)) {
                $referredByUserId = User::whereKey($m[1])->value('id');
            }

            $user = User::create([
                'name' => $googleUser->getName() ?? $googleUser->getNickname() ?? 'Traveler',
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'avatar_url' => $googleUser->getAvatar(),
                'referral_source' => $refSource,
                'referred_by_user_id' => $referredByUserId,
            ]);

            // The full influencer attribution (CLAUDE.md section 6) is separate from both the
            // lightweight `referral_source` string and the user-to-user credit referral above —
            // only fires when `?ref=` matches a real, partner-owned ReferralCode. Skipped
            // entirely once a user-to-user match already won (mutually exclusive ref formats).
            if (! $referredByUserId) {
                app(ReferralAttributionService::class)->attribute($user, $refSource, $request->ip());
            }
        }

        session()->forget('pending_referral_source');
        Auth::login($user, remember: true);

        return redirect(config('app.frontend_url'));
    }

    public function logout(): RedirectResponse
    {
        Auth::logout();

        return redirect(config('app.frontend_url'));
    }
}
