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
        Schema::create('search_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('trip_type_id')->nullable()->constrained('taxonomy_nodes')->nullOnDelete();

            $table->unsignedInteger('adults_count')->nullable();
            $table->jsonb('children_ages')->nullable();
            $table->jsonb('needs_crib')->nullable();

            $table->foreignId('group_type_id')->nullable()->constrained('taxonomy_nodes')->nullOnDelete();
            $table->foreignId('persona_id')->nullable()->constrained('taxonomy_nodes')->nullOnDelete();

            $table->string('termin_category')->nullable();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();

            $table->foreignId('country_region_id')->nullable()->constrained('taxonomy_nodes')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('taxonomy_nodes')->nullOnDelete();

            $table->jsonb('free_text_answers')->nullable();

            $table->string('status')->default('in_progress');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_sessions');
    }
};
