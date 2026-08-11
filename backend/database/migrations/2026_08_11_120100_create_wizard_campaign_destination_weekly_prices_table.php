<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner's ask, 2026-08-11: prices drop as the season progresses, and the owner wants to
// enter/update a price PER WEEK (Saturday-aligned, matching typical charter/package check-in
// day) rather than one flat price for the whole campaign. `wizard_campaign_destination_price_id`
// (not campaign_id + taxonomy_node_id directly) — the parent row still owns includes_meals/
// notes/source (destination-level properties, don't vary week to week) and its own
// price_per_person_eur becomes the fallback for a destination that has no weekly rows at all
// yet (see WizardCampaignDestinationPrice::estimateAccommodationTotal).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wizard_campaign_destination_weekly_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wizard_campaign_destination_price_id')
                ->constrained('wizard_campaign_destination_prices')->cascadeOnDelete();
            $table->date('week_start_date'); // always a Saturday, see WizardCampaign::seasonWeeks()
            $table->decimal('price_per_person_eur', 8, 2)->nullable();
            $table->timestamps();

            $table->unique(['wizard_campaign_destination_price_id', 'week_start_date'], 'wcdwp_price_week_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wizard_campaign_destination_weekly_prices');
    }
};
