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
        Schema::create('taxonomy_node_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_taxonomy_node_id')->constrained('taxonomy_nodes')->cascadeOnDelete();
            $table->foreignId('to_taxonomy_node_id')->constrained('taxonomy_nodes')->cascadeOnDelete();
            // 'implies'  — selecting `from` silently records `to` elsewhere AND hides `to` as its
            //              own choice (e.g. Gurman -> dobra_hrana preference_tag: don't ask again).
            // 'suggests' — selecting `from` pre-fills `to` as a visible, editable follow-up answer
            //              (e.g. jeftino -> budget_tier do_20e: shown as "20€/noć, ili upiši svoje").
            // 'excludes' — selecting `from` removes `to` from whatever question offers it
            //              (e.g. City break -> Letovanje). Directional on purpose: the reverse
            //              (Letovanje -> City break) is NOT implied automatically — a summer-style
            //              trip booked for "next winter" is still valid, it just changes which
            //              geography gets suggested (southern hemisphere). See wizard_architecture.
            $table->string('relation_type');
            $table->timestamps();

            $table->unique(['from_taxonomy_node_id', 'to_taxonomy_node_id', 'relation_type'], 'taxonomy_relation_unique');
        });

        // Plain string + CHECK (not a native Postgres enum type) so changing the allowed
        // values later is a cheap DROP/ADD CONSTRAINT instead of ALTER TYPE gymnastics.
        DB::statement("ALTER TABLE taxonomy_node_relations ADD CONSTRAINT taxonomy_node_relations_no_self_ref CHECK (from_taxonomy_node_id <> to_taxonomy_node_id)");
        DB::statement("ALTER TABLE taxonomy_node_relations ADD CONSTRAINT taxonomy_node_relations_type_check CHECK (relation_type IN ('implies','suggests','excludes'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('taxonomy_node_relations');
    }
};
