<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Key-value (EAV-shaped) rather than fixed columns like taxonomy_node_climates —
     * deliberately different from that table's shape because the CATEGORY set here is
     * open-ended and expected to keep growing (alcohol/pork/halal/vegan/organic/cannabis/
     * dress_code/lgbtq_friendly/tap_water decided 2026-07-30, more likely later), so a new
     * category must be addable as a seed row, never a migration. See wizard_architecture
     * memory for the full discussion (owner independently arrived at the same "dedicated
     * table over jsonb" reasoning already used for climate, then the EAV refinement).
     *
     * `tier` convention, consistent across EVERY category without exception: 1 = most
     * freely available/liberal, 4 = most restricted/hardest to find. This is what makes a
     * generic `WHERE tier <= X` comparison meaningful across categories the caller doesn't
     * need to special-case.
     */
    public function up(): void
    {
        Schema::create('cultural_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taxonomy_node_id')->constrained('taxonomy_nodes')->cascadeOnDelete();
            $table->string('category');
            $table->unsignedTinyInteger('tier');
            $table->string('label')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();

            $table->unique(['taxonomy_node_id', 'category']);
        });

        DB::statement('ALTER TABLE cultural_availability ADD CONSTRAINT cultural_availability_tier_check CHECK (tier BETWEEN 1 AND 4)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cultural_availability');
    }
};
