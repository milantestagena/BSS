<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Positive, explicit "this campaign firmly owns these destinations" membership — 2026-09-18,
     * owner's ask after region_theme-level `excludes` edges silently failed to cascade to newly
     * added child countries (Hungary/Austria/Bosnia/N.Macedonia leaked into kasno-letovanje/
     * zimsko-sunce because nobody also added the matching per-country excludes — see
     * GeographyResolver::filterByCampaignOwnership). A row can point at a region_theme, country,
     * OR city node — see that method's docblock for the exact ownership/reachability rules.
     * Mirrors wizard_campaign_questions' shape exactly — no `relation_type`-style payload column,
     * a row's meaning is fully determined by which TYPE of taxonomy_node it points at.
     */
    public function up(): void
    {
        Schema::create('wizard_campaign_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wizard_campaign_id')->constrained('wizard_campaigns')->cascadeOnDelete();
            $table->foreignId('taxonomy_node_id')->constrained('taxonomy_nodes')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['wizard_campaign_id', 'taxonomy_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wizard_campaign_destinations');
    }
};
