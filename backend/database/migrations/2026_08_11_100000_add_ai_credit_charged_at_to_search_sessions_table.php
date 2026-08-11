<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bug fixed 2026-08-11: generateHonestReport is called once per listing shown on the results
// screen (10 mock hotels today), and AiCreditsDirective charged a credit on EVERY call — 10
// credits for one search instead of the intended "1 credit per complete search session"
// (CLAUDE.md section 3: "Krediti se troše po kompletnoj search sesiji, ne po AI pozivu"). This
// marks the moment a session's first AI charge happens, so every later call in the SAME session
// resolves for free instead of charging again.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_sessions', function (Blueprint $table) {
            $table->timestamp('ai_credit_charged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('search_sessions', function (Blueprint $table) {
            $table->dropColumn('ai_credit_charged_at');
        });
    }
};
