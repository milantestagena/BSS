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
            // Where the user is traveling FROM — its own step/question, own session_field, same
            // `city` taxonomy_type as the destination city question (owner's explicit call, see
            // wizard_architecture: distance is arithmetic over two coordinates, not a
            // preference_tag, so it doesn't belong in that resolution path). Distance itself is
            // computed on demand (TaxonomyNode::distanceKmTo / SearchSession::distanceFromHomeKm),
            // not stored, since it depends on which destination city is compared against.
            $table->foreignId('home_city_id')->nullable()->after('city_id')
                ->constrained('taxonomy_nodes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('search_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('home_city_id');
        });
    }
};
