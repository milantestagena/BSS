<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real, campaign-scoped accommodation price entry — owner's call, 2026-08-05, superseding
     * (for the "kasno-letovanje" campaign specifically) the previous night's van_sezone/
     * pred_post_sezona/sezona tier idea: "cene nisu ni sezonske ni van sezonske" — this
     * campaign targets one narrow late-season window, so a per-(campaign, destination) EUR
     * price per person is simpler and more direct than seasonal tiers, which stay real
     * infrastructure for future full-year/multi-window functionality.
     *
     * Owner's own derivation method (off-app, not encoded here): take a real Booking listing
     * for a mid-size group (e.g. a 3-bed apartment), divide by ~2.5, add ~20% margin over the
     * cheapest observed option. `price_per_person_eur` nullable on purpose — rows get
     * pre-created (empty) for every destination via `campaign:seed-destination-price-rows` so
     * the admin table is a fill-in-the-blanks grid, not one-at-a-time record creation.
     *
     * IMPORTANT: unlike every other table this project has freely `migrate:fresh`'d through
     * tonight, this one holds genuinely hand-typed, non-reproducible data the moment the owner
     * starts filling it in — from this point on, schema changes affecting this table (or any
     * migration run at all once real prices exist) MUST use a normal additive `migrate`, never
     * `migrate:fresh`.
     */
    public function up(): void
    {
        Schema::create('wizard_campaign_destination_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wizard_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('taxonomy_node_id')->constrained('taxonomy_nodes')->cascadeOnDelete();
            $table->decimal('price_per_person_eur', 8, 2)->nullable();
            $table->string('notes')->nullable();
            $table->string('source')->default('manual_website');
            $table->timestamps();

            $table->unique(['wizard_campaign_id', 'taxonomy_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wizard_campaign_destination_prices');
    }
};
