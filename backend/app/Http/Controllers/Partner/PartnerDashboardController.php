<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class PartnerDashboardController extends Controller
{
    public function index()
    {
        $partner = Auth::guard('partner')->user();

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
