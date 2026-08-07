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
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            // Generic polymorphic target (TaxonomyNode, WizardStep, WizardQuestion, ...) rather
            // than one near-identical table per model — see wizard_architecture / i18n decision.
            $table->string('translatable_type');
            $table->unsignedBigInteger('translatable_id');
            $table->string('field'); // which column on the target this translates, e.g. 'label'
            $table->string('locale'); // 'sr', 'de', 'fr', ...
            $table->text('value');
            $table->string('source_hash'); // hash of the English value at translation time
            $table->string('status')->default('machine'); // 'machine' | 'human' | 'stale'
            $table->timestamps();

            $table->unique(['translatable_type', 'translatable_id', 'field', 'locale'], 'translations_unique');
            $table->index(['translatable_type', 'translatable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
