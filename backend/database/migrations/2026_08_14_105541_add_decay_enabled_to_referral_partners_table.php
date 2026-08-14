<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner's ask, 2026-08-14: the standard decay curve (50% -> 15% -> 5% -> 0%, see
// CommissionShare::decayTierPercentage) was previously hardcoded for every partner past tier 1
// — no way to give a specific partner (e.g. a micro-influencer blogger cohort) a FLAT rate on
// every booking forever instead. Owner's own reasoning: "ako mnogo dobrih dovede, mnogo mi je
// doneo prihod" — paying out more when a partner brings a lot of genuinely good referrals isn't
// a risk to cap, it's proportional to revenue they generated. Default true (decay curve, current
// behavior unchanged) so every existing partner keeps working exactly as before.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_partners', function (Blueprint $table) {
            $table->boolean('decay_enabled')->default(true)->after('share_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('referral_partners', function (Blueprint $table) {
            $table->dropColumn('decay_enabled');
        });
    }
};
