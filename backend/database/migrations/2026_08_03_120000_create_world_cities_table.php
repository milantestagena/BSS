<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GeoNames' cities15000 dump (cities with population > 15000, ~34k rows worldwide, CC BY
     * 4.0) — powers the home_city typeahead (owner's explicit ask, 2026-07-30: "treba nam baza
     * sa listom lokacija i LAT LONG"). Deliberately separate from `taxonomy_nodes` — this is a
     * huge, generic world-city catalog for "where are you traveling FROM", not our small
     * hand-curated destination content tree. Most world_cities rows will never become a
     * taxonomy_node; see WorldCityResolver for the find-or-create bridge when one is actually
     * picked as a home city (reuses the existing home_city_id -> taxonomy_nodes machinery
     * rather than inventing a second one).
     */
    public function up(): void
    {
        Schema::create('world_cities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('geoname_id')->unique();
            $table->string('name');
            $table->string('ascii_name');
            $table->string('country_code', 2);
            $table->decimal('lat', 9, 6);
            $table->decimal('lng', 9, 6);
            $table->unsignedInteger('population')->nullable();
            $table->timestamps();

            $table->index('ascii_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('world_cities');
    }
};
