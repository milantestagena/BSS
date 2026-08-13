<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Owner's ask, 2026-08-13: default budget (per adult / per child) shouldn't be
        // hardcoded — "da mozemo da podesimo po kampanji" — same generic admin-editable JSON
        // convention already used for termin_category (honest_report_thresholds etc.), not a
        // dedicated column per tunable value.
        Schema::table('wizard_campaigns', function (Blueprint $table) {
            $table->jsonb('meta')->nullable()->after('season_end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wizard_campaigns', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
