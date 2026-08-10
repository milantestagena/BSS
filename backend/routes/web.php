<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Partner\PartnerAuthController;
use App\Http\Controllers\Partner\PartnerDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// See CLAUDE.md section 5/8, "Login preko Google-a" — see GoogleAuthController's docblock.
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
Route::get('/auth/logout', [GoogleAuthController::class, 'logout'])->name('auth.logout');

// Referral partner (influencer) login + dashboard — see CLAUDE.md section 6. Plain
// server-rendered Blade, not Angular: this is an internal tool for a handful of manually
// onboarded partners, not the customer-facing wizard, so it doesn't need the SPA/GraphQL
// stack. Same-origin session auth via the 'partner' guard — no CORS complications here.
Route::get('/partner/login', [PartnerAuthController::class, 'showLogin'])->name('partner.login');
Route::post('/partner/login', [PartnerAuthController::class, 'login'])->name('partner.login.submit');
Route::post('/partner/logout', [PartnerAuthController::class, 'logout'])->name('partner.logout');
Route::middleware('auth:partner')->get('/partner/dashboard', [PartnerDashboardController::class, 'index'])->name('partner.dashboard');
