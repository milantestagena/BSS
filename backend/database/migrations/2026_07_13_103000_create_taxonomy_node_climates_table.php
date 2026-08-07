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
        // Dedicated table, not `meta` jsonb — unlike cost-of-living (1 row per location),
        // climate is 12 rows per location, which is exactly the "jsonb awkward" case flagged
        // in project_dev_phases. Real SQL columns also let a future ranking query actually
        // WHERE/filter by month + threshold, which jsonb path digging can't do efficiently.
        Schema::create('taxonomy_node_climates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taxonomy_node_id')->constrained('taxonomy_nodes')->cascadeOnDelete();
            $table->unsignedTinyInteger('month');
            $table->decimal('avg_temp_c', 4, 1)->nullable();
            $table->decimal('precip_mm', 6, 1)->nullable();
            $table->decimal('sun_hours', 6, 1)->nullable();
            $table->decimal('snow_cm', 6, 1)->nullable();
            // Traceability, same convention as the hospitality/local_stores/transport meta —
            // lets a future periodic-refresh job find stale rows.
            $table->string('source')->nullable();
            $table->timestamps();

            $table->unique(['taxonomy_node_id', 'month']);
        });

        DB::statement('ALTER TABLE taxonomy_node_climates ADD CONSTRAINT taxonomy_node_climates_month_check CHECK (month BETWEEN 1 AND 12)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('taxonomy_node_climates');
    }
};
