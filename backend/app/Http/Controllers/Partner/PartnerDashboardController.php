<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

// Resellers log in the same "Sign in with Google" way as any other customer (see
// GoogleAuthController) — there is no separate partner login. This just reads the normal
// 'web' guard session and looks up whether that user has been promoted to a reseller.
class PartnerDashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('auth.google.redirect', ['ref' => request('ref')]);
        }

        $partner = $user->resellerProfile;

        if (! $partner) {
            abort(403, "This account doesn't have reseller access.");
        }

        $attributions = $partner->referralAttributions()
            ->with(['user', 'code', 'commissionShares'])
            ->latest()
            ->get();

        $commissionShares = $attributions->pluck('commissionShares')->flatten();

        $totals = [
            'pending' => $commissionShares->where('status', 'pending')->sum('estimated_amount_eur'),
            'reconciled' => $commissionShares->where('status', 'reconciled')->sum('estimated_amount_eur'),
            'paid' => $commissionShares->where('status', 'paid')->sum('estimated_amount_eur'),
        ];

        return view('partner.dashboard', [
            'partner' => $partner,
            'attributions' => $attributions,
            'commissionShares' => $commissionShares->sortByDesc('created_at'),
            'totals' => $totals,
        ]);
    }
}
