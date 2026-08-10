<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Partner\PartnerDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// See CLAUDE.md section 5/8, "Login preko Google-a" — see GoogleAuthController's docblock.
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
Route::get('/auth/logout', [GoogleAuthController::class, 'logout'])->name('auth.logout');

// Reseller (influencer partner) dashboard — see CLAUDE.md section 6. Plain server-rendered
// Blade, not Angular: internal tool for a handful of promoted users, not the customer-facing
// wizard. Logs in via the SAME "Sign in with Google" flow as every customer (normal 'web'
// guard session) — PartnerDashboardController itself checks whether that user has been
// promoted to a reseller, redirecting to Google if not even logged in yet.
Route::get('/partner/dashboard', [PartnerDashboardController::class, 'index'])->name('partner.dashboard');
