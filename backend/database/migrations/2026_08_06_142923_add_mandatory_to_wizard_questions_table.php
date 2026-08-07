<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Mandatory question" as a first-class, extensible concept (owner's call, 2026-08-06) — the
 * frontend blocks Proceed on the current step until every question flagged here is answered.
 * Starts with exactly one (total_budget: "ako nam nista ne kaze, ne mozemo nista da mu vratimo
 * ko data, a da valja" — without it, budget fit / suggested amenities have nothing to compute
 * against), but the flag is generic so more can be added later without new code, same
 * admin-editable-data convention as the rest of this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wizard_questions', function (Blueprint $table) {
            $table->boolean('mandatory')->default(false)->after('allow_free_text');
        });
    }

    public function down(): void
    {
        Schema::table('wizard_questions', function (Blueprint $table) {
            $table->dropColumn('mandatory');
        });
    }
};
