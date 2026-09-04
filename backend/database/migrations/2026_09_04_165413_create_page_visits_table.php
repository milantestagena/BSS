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
        // Owner's ask, 2026-09-04 (day after launch): "kolko je ljudi bilo dnevno" — a plain
        // daily-visit count, broken down by location. Deliberately holds NO ip_address column —
        // resolved via IpGeolocationClient (same client WorldCityResolver::detectHomeCity
        // already uses) at request time, then the raw IP is discarded, only country/city kept.
        // No FK to search_sessions — most visits never start a session at all, this counts raw
        // page loads, a different, earlier signal than wizard_events.
        Schema::create('page_visits', function (Blueprint $table) {
            $table->id();
            $table->string('path');
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_visits');
    }
};
