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
        // Mirrors Booking.com's own location catalog (common/locations/{countries,cities,
        // districts,airports,landmarks,regions}) — separate from `taxonomy_nodes` on purpose.
        // taxonomy_nodes is our small, hand-curated content tree (vibe/climate/cost tags);
        // this table is Booking's raw, huge, structural catalog (potentially tens of thousands
        // of rows once real API access exists). Most locations will never get a taxonomy node;
        // most taxonomy nodes don't have one yet (see taxonomy_nodes.booking_location_id).
        // Column shape is our best guess from researched docs, NOT yet verified against a real
        // sandbox response (see wizard_architecture/project_dev_phases 2026-07-13) — expect an
        // additive follow-up migration once real field names are confirmed.
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('booking_dest_id')->unique();
            $table->string('dest_type'); // city/region/district/airport/landmark/country
            $table->string('name');
            $table->string('country_code')->nullable();
            $table->unsignedInteger('nr_hotels')->nullable();
            $table->string('source')->default('manual_test');
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
