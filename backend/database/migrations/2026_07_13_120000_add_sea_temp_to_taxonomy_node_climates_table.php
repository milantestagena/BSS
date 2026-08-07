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
        Schema::table('taxonomy_node_climates', function (Blueprint $table) {
            // Water temp, not air temp — the actual deciding factor for "can I swim here in
            // November" (see the late-season-swim theme research, 2026-07-13). Null for
            // non-coastal locations, same convention as snow_cm for non-ski ones. Only
            // meaningful where the city's meta.on_sea is true, but not FK-enforced — that'd be
            // over-constraining a jsonb convention with a hard schema rule for no real benefit.
            $table->decimal('sea_temp_c', 4, 1)->nullable()->after('avg_temp_c');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('taxonomy_node_climates', function (Blueprint $table) {
            $table->dropColumn('sea_temp_c');
        });
    }
};
