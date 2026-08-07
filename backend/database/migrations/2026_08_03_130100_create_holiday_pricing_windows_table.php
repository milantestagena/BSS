<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Global (not per-country) holiday price-spike windows — v1 scope decision, 2026-08-03,
     * see wizard_architecture memory. Each row is either a fixed month/day (may_day,
     * christmas_newyear) or is_easter_based (easter_calendar picks which date algorithm —
     * western via PHP's easter_date(), orthodox via a Meeus-algorithm calculation in
     * AccommodationPriceEstimator, since Greece/Cyprus — two of the ten seeded swim
     * countries — follow the Orthodox calendar and can land weeks apart from the West).
     * window_before_days/window_after_days replace the vaguer "bridge_days" idea from the
     * original sketch — a plain symmetric-ish padding around the anchor date is enough to
     * catch long-weekend bridging without needing actual day-of-week logic in v1.
     *
     * Which Easter calendar applies (western vs orthodox) is a property of the DESTINATION
     * country, not of this global window row — see TaxonomyNode.meta['easter_calendar'],
     * defaulted to 'western' when unset, read by AccommodationPriceEstimator.
     */
    public function up(): void
    {
        Schema::create('holiday_pricing_windows', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->unsignedTinyInteger('month')->nullable();
            $table->unsignedTinyInteger('day')->nullable();
            $table->boolean('is_easter_based')->default(false);
            $table->unsignedTinyInteger('window_before_days')->default(0);
            $table->unsignedTinyInteger('window_after_days')->default(0);
            $table->decimal('price_multiplier', 4, 2);
            $table->string('source')->default('manual_estimate');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_pricing_windows');
    }
};
