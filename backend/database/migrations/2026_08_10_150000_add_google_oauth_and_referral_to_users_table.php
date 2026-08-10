<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * See CLAUDE.md section 5/8 — "Login preko Google-a". `password` becomes nullable since
 * Google-authenticated accounts never set one. `referral_source` is a lightweight capture of
 * whatever ?ref= code brought this user in (see wizard_architecture, 2026-08-10) — deliberately
 * NOT the full influencer ReferralPartner/ReferralAttribution system (CLAUDE.md section 6),
 * which is a later, separate phase this only lays groundwork for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('google_id')->nullable()->unique()->after('email');
            $table->string('avatar_url')->nullable()->after('google_id');
            $table->string('referral_source')->nullable()->after('avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google_id', 'avatar_url', 'referral_source']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
