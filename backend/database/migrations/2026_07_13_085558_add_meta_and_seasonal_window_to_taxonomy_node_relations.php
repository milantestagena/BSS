<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('taxonomy_node_relations', function (Blueprint $table) {
            // Payload for relation types that carry data beyond the edge itself — currently
            // only 'seasonal_window' (e.g. {"months": [6,7,8]}), which location a (location,
            // termin_category) pair is good for is per-pair, not a global tag on either node
            // (see wizard_architecture, Patagonia counter-example). Null for implies/suggests/
            // excludes, which are pure edges.
            $table->jsonb('meta')->nullable()->after('relation_type');
        });

        DB::statement('ALTER TABLE taxonomy_node_relations DROP CONSTRAINT taxonomy_node_relations_type_check');
        DB::statement("ALTER TABLE taxonomy_node_relations ADD CONSTRAINT taxonomy_node_relations_type_check CHECK (relation_type IN ('implies','suggests','excludes','seasonal_window'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE taxonomy_node_relations DROP CONSTRAINT taxonomy_node_relations_type_check');
        DB::statement("ALTER TABLE taxonomy_node_relations ADD CONSTRAINT taxonomy_node_relations_type_check CHECK (relation_type IN ('implies','suggests','excludes'))");

        Schema::table('taxonomy_node_relations', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
