<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same shape/parent-fallback pattern as taxonomy_node_climates — one row per
     * (node, month), season_tier is one of the owner's own three named tiers (2026-08-03).
     * `praznici` (holidays) is NOT a value here — it's a separate overlay, see
     * holiday_pricing_windows, since a holiday can fall inside any of these three base
     * seasons and spikes on top of whichever one it lands in.
     */
    public function up(): void
    {
        Schema::create('taxonomy_node_accommodation_seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taxonomy_node_id')->constrained('taxonomy_nodes')->cascadeOnDelete();
            $table->unsignedTinyInteger('month');
            $table->string('season_tier');
            $table->string('source')->default('manual_estimate');
            $table->timestamps();

            $table->unique(['taxonomy_node_id', 'month']);
        });

        DB::statement('ALTER TABLE taxonomy_node_accommodation_seasons ADD CONSTRAINT tnas_month_check CHECK (month BETWEEN 1 AND 12)');
        DB::statement("ALTER TABLE taxonomy_node_accommodation_seasons ADD CONSTRAINT tnas_tier_check CHECK (season_tier IN ('van_sezone', 'pred_post_sezona', 'sezona'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_node_accommodation_seasons');
    }
};
