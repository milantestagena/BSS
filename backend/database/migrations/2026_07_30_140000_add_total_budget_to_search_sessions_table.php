<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Total trip spending budget (EUR) — deliberately separate from `budget_tier_id`
     * (accommodation price per night only). Feeds BudgetEstimationEngine, which compares it
     * against per-country hospitality/local_stores cost meta to narrow candidate countries.
     * See wizard_architecture memory, 2026-07-30 "Wizard tree design" — this is the "warm-up"
     * question, asked once, upfront, not tied to any specific destination.
     */
    public function up(): void
    {
        Schema::table('search_sessions', function (Blueprint $table) {
            $table->decimal('total_budget', 10, 2)->nullable()->after('number_of_rooms');
        });
    }

    public function down(): void
    {
        Schema::table('search_sessions', function (Blueprint $table) {
            $table->dropColumn('total_budget');
        });
    }
};
