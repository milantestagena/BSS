<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // No new column — reuses the `meta` jsonb column added for seasonal_window. Payload
        // shape here is {"weight": 1-3}, same scale as WizardQuestion.importance_weight (not
        // yet built) so the whole app has one weighting convention, not two.
        DB::statement('ALTER TABLE taxonomy_node_relations DROP CONSTRAINT taxonomy_node_relations_type_check');
        DB::statement("ALTER TABLE taxonomy_node_relations ADD CONSTRAINT taxonomy_node_relations_type_check CHECK (relation_type IN ('implies','suggests','excludes','seasonal_window','weighted_toward'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE taxonomy_node_relations DROP CONSTRAINT taxonomy_node_relations_type_check');
        DB::statement("ALTER TABLE taxonomy_node_relations ADD CONSTRAINT taxonomy_node_relations_type_check CHECK (relation_type IN ('implies','suggests','excludes','seasonal_window'))");
    }
};
