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
        // Raw, dumb log table — owner's ask, 2026-08-13: "kad imamo sirovo lako ce napravimo
        // usable report". No aggregation/dashboard here, just every event recorded so a real
        // funnel/report can be built later once there's real traffic to look at. `search_session_id`
        // has no FK constraint on purpose — sessions are cheap/anonymous and this table should
        // outlive any future session-pruning without needing a cascade decision now.
        Schema::create('wizard_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('search_session_id')->nullable()->index();
            $table->string('event_type')->index();
            $table->jsonb('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wizard_events');
    }
};
