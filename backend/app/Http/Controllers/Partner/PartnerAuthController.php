<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Simple email+password login for referral partners — separate 'partner' guard, see
// config/auth.php. NOT a self-signup flow: accounts are created manually in Filament (see
// CLAUDE.md section 6, "ručno pregovaran po partneru"), so there is no register() here.
class PartnerAuthController extends Controller
{
    public function showLogin()
    {
        return view('partner.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('partner')->attempt($credentials, remember: true)) {
            return back()->withErrors(['email' => 'Invalid credentials.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->route('partner.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('partner')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('partner.login');
    }
}
