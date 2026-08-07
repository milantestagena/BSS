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
        Schema::table('search_sessions', function (Blueprint $table) {
            $table->unsignedInteger('number_of_rooms')->nullable()->after('needs_crib');
            $table->foreignId('budget_tier_id')->nullable()->after('persona_id')
                ->constrained('taxonomy_nodes')->nullOnDelete();
            $table->foreignId('tip_smestaja_id')->nullable()->after('budget_tier_id')
                ->constrained('taxonomy_nodes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('search_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('budget_tier_id');
            $table->dropConstrainedForeignId('tip_smestaja_id');
            $table->dropColumn('number_of_rooms');
        });
    }
};
