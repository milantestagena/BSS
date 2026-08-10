<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
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

    public function callback(): RedirectResponse
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::firstWhere('google_id', $googleUser->getId())
            ?? User::firstWhere('email', $googleUser->getEmail());

        if ($user) {
            if (! $user->google_id) {
                $user->update(['google_id' => $googleUser->getId(), 'avatar_url' => $googleUser->getAvatar()]);
            }
        } else {
            $user = User::create([
                'name' => $googleUser->getName() ?? $googleUser->getNickname() ?? 'Traveler',
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'avatar_url' => $googleUser->getAvatar(),
                'referral_source' => session('pending_referral_source'),
            ]);
        }

        session()->forget('pending_referral_source');
        Auth::login($user, remember: true);

        return redirect(config('app.url'));
    }

    public function logout(): RedirectResponse
    {
        Auth::logout();

        return redirect(config('app.url'));
    }
}
