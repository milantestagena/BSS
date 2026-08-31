<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Real per-destination meal-plan availability, owner's ask 2026-08-31: a Turkish hotel
     * search offered Breakfast/Breakfast&dinner/All-inclusive/Self catering but NOT
     * "Breakfast & lunch" or "All meals included" — the wizard's meal_plan_preference multi-
     * select was letting a session pick a combination that isn't realistically bookable in that
     * market, and the budget-fit math happily "passed" it anyway. Same shape as
     * implies/excludes/suggests (from = country/city taxonomy node, to = meal_plan taxonomy
     * node) rather than a new table — a country/city "offering" a meal_plan is exactly the same
     * kind of directed edge. No new columns needed, `meta` already exists for payload-carrying
     * types (unused here, edges are pure like implies/excludes).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE taxonomy_node_relations DROP CONSTRAINT taxonomy_node_relations_type_check');
        DB::statement("ALTER TABLE taxonomy_node_relations ADD CONSTRAINT taxonomy_node_relations_type_check CHECK (relation_type IN ('implies','suggests','excludes','seasonal_window','weighted_toward','offers_meal_plan'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE taxonomy_node_relations DROP CONSTRAINT taxonomy_node_relations_type_check');
        DB::statement("ALTER TABLE taxonomy_node_relations ADD CONSTRAINT taxonomy_node_relations_type_check CHECK (relation_type IN ('implies','suggests','excludes','seasonal_window','weighted_toward'))");
    }
};
