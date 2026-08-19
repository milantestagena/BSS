<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional "deep-dive" destination content (itinerary/costs/tips/photos), owner's ask,
     * 2026-08-19 — same (wizard_campaign_id, taxonomy_node_id) uniqueness shape as
     * `wizard_campaign_destination_prices` on purpose: this is place+CAMPAIGN scoped, not just
     * place-scoped, since relevant advice/pricing differs by which season/campaign a
     * destination is being shown under.
     *
     * Deliberately does NOT store a price, dates, or departure city — those are session-
     * specific/live, not place+campaign-static, and storing a price hook here would risk
     * exactly the kind of staleness this project has been careful to avoid elsewhere (see the
     * reverted budget_shortfall_eur feature). Real accommodation cost is read live off
     * `wizard_campaign_destination_prices` at render time (see DestinationGuide model).
     *
     * `itinerary` is country-level only (a multi-stop route only makes sense for a large/
     * diverse destination) — null for city-level guides (single resort towns like Alanya have
     * nothing to itinerary-ize). Static content, refreshed periodically by Claude on request or
     * before a new campaign cycle — never generated live per page view, same `manual_estimate`/
     * researched-and-stored convention as vibe_profile/hospitality/cultural_availability
     * elsewhere in this project.
     */
    public function up(): void
    {
        Schema::create('destination_guides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wizard_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('taxonomy_node_id')->constrained('taxonomy_nodes')->cascadeOnDelete();

            // [{location, nights, highlight}] — country-level only, null for cities.
            $table->jsonb('itinerary')->nullable();
            // Qualitative only — never a hardcoded € figure, see class docblock.
            $table->text('accommodation_cost_notes')->nullable();
            // 2-4 genuinely destination-specific tips NOT already covered by cultural_availability/
            // hospitality meta (those get composed client-side instead of duplicated here).
            $table->jsonb('extra_tips')->nullable();
            // [{url, attribution}] — Unsplash/Pexels only. NEVER Booking's own images: same
            // "automated means"/ToS risk this project already rejected for auto-pulling prices.
            $table->jsonb('images')->nullable();

            $table->date('researched_at')->nullable();
            $table->string('source')->default('manual_research');
            $table->timestamps();

            $table->unique(['wizard_campaign_id', 'taxonomy_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_guides');
    }
};
