<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's ask, 2026-09-08: a second, optional price per week for the "quality" tier (8+ guest
 * rating / 4+ star, per Hotels.com's own filter-sidebar price-by-tier display — see
 * HotelsComFilters::STAR/GUEST_RATING) — a REAL price for that tier, not the current `kvalitet`
 * preference's proxy (re-sorting the existing flat price by descending order within budget, see
 * GeographyResolver's $costPreference branch). Nullable, additive, same pattern as
 * includes_meals — this table already holds genuinely hand-typed, non-reproducible data once
 * filled in, so only ever `migrate` here, never `migrate:fresh` (see the parent price table's
 * migration docblock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wizard_campaign_destination_weekly_prices', function (Blueprint $table) {
            $table->decimal('quality_tier_price_per_person_eur', 8, 2)->nullable()->after('price_per_person_eur');
        });
    }

    public function down(): void
    {
        Schema::table('wizard_campaign_destination_weekly_prices', function (Blueprint $table) {
            $table->dropColumn('quality_tier_price_per_person_eur');
        });
    }
};
