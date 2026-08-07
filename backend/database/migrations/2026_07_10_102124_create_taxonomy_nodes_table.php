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
        Schema::create('taxonomy_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('taxonomy_nodes')->nullOnDelete();
            $table->string('type');
            $table->string('slug');
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->unique(['type', 'slug']);
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('taxonomy_nodes');
    }
};
