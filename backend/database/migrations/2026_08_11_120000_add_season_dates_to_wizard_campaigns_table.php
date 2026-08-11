<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner's ask, 2026-08-11: per-week destination pricing (Saturday-to-Saturday, matching
// typical charter/package check-in day) needs a campaign-wide date range to anchor which
// calendar week each Saturday-start row actually is — a WizardCampaign had no date range of
// its own before this (see migration comment on create_wizard_campaigns_table: "cene nisu ni
// sezonske ni van sezonske" was true for a single flat price, no longer true once price varies
// week to week within the campaign).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wizard_campaigns', function (Blueprint $table) {
            $table->date('season_start_date')->nullable()->after('landing_headline');
            $table->date('season_end_date')->nullable()->after('season_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('wizard_campaigns', function (Blueprint $table) {
            $table->dropColumn(['season_start_date', 'season_end_date']);
        });
    }
};
