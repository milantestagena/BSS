<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real, manually-observed nightly accommodation prices — owner's own name for this
     * entity, 2026-08-03 ("otvoricemo entitet za cene, nazvacemo ga late summer"). Owner
     * browses Booking.com's map by hand per destination/date-range and records what's
     * actually there; `source` stays 'manual_website' (matches the existing convention: a
     * real number, read off a real page, at a point in time — not invented, but not a live
     * feed either). One row per (destination, tier) — re-observing just overwrites, no
     * history log, see [[feedback_engineering_standards]] (no premature abstraction).
     *
     * This is the REAL layer that AccommodationPriceEstimator checks first, before falling
     * back to the global manual_estimate multiplier from taxonomy_node_accommodation_seasons
     * + holiday_pricing_windows.
     */
    public function up(): void
    {
        Schema::create('late_summer_accommodation_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taxonomy_node_id')->constrained('taxonomy_nodes')->cascadeOnDelete();
            $table->string('season_tier');
            $table->decimal('price_per_night_eur', 8, 2);
            $table->string('notes')->nullable();
            $table->date('observed_at');
            $table->string('source')->default('manual_website');
            $table->timestamps();

            $table->unique(['taxonomy_node_id', 'season_tier']);
        });

        DB::statement("ALTER TABLE late_summer_accommodation_prices ADD CONSTRAINT lsap_tier_check CHECK (season_tier IN ('van_sezone', 'pred_post_sezona', 'sezona', 'praznici'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('late_summer_accommodation_prices');
    }
};
