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
        Schema::create('wizard_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wizard_step_id')->constrained('wizard_steps')->cascadeOnDelete();
            $table->string('key')->unique();
            $table->string('label');
            // taxonomy_choice | number | number_array | date_range | boolean | text
            $table->string('input_type');
            $table->string('taxonomy_type')->nullable();
            $table->string('session_field')->nullable();
            $table->boolean('allow_free_text')->default(false);
            $table->foreignId('depends_on_taxonomy_node_id')->nullable()
                ->constrained('taxonomy_nodes')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wizard_questions');
    }
};
