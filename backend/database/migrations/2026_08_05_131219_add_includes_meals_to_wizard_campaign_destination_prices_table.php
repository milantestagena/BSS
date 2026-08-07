<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner's catch, 2026-08-05: Egypt's three seeded destinations (Hurghada, Sharm El Sheikh,
     * Marsa Alam) are almost always booked all-inclusive/full-board — the price already
     * bundles meals, unlike a bare Spain/Greece apartment. Without this flag,
     * BudgetEstimationEngine's food estimate would be added a SECOND time on top of an
     * already-food-inclusive price. Per-destination (not hardcoded to Egypt) so any future
     * all-inclusive-heavy destination can be flagged the same way, no code change needed.
     */
    public function up(): void
    {
        Schema::table('wizard_campaign_destination_prices', function (Blueprint $table) {
            $table->boolean('includes_meals')->default(false)->after('price_per_person_eur');
        });
    }

    public function down(): void
    {
        Schema::table('wizard_campaign_destination_prices', function (Blueprint $table) {
            $table->dropColumn('includes_meals');
        });
    }
};
