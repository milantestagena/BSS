<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A themed landing entry (e.g. "Kasno letovanje") — see wizard_architecture memory,
     * 2026-07-29/30. `preset_answers` writes values into a session directly at start (no
     * question asked for those fields, e.g. termin_category='kasno_kupanje'). The SAME global
     * `wizard_questions` (unchanged mapping) get selected and ordered per campaign via the
     * `wizard_campaign_questions` pivot below — `wizard_steps` stay global/shared.
     */
    public function up(): void
    {
        Schema::create('wizard_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('landing_headline')->nullable();
            $table->jsonb('preset_answers')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('wizard_campaign_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wizard_campaign_id')->constrained('wizard_campaigns')->cascadeOnDelete();
            $table->foreignId('wizard_question_id')->constrained('wizard_questions')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['wizard_campaign_id', 'wizard_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wizard_campaign_questions');
        Schema::dropIfExists('wizard_campaigns');
    }
};
