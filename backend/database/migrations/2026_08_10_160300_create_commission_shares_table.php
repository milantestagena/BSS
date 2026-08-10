<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per confirmed booking that traces back to a referral_attribution — see CLAUDE.md
// section 6. `booking_sequence_number` is which confirmed booking this is FOR THAT USER (1st,
// 2nd, 3rd, 4th+), which is what the decay-tier percentage (50/15/5/0) is keyed on.
// `estimated_amount_eur` is an estimate until the monthly CSV reconciliation from Booking's
// Partner Centre corrects it (no Details API access below the 20k-bookings/year threshold —
// see CLAUDE.md). `status`: pending -> reconciled -> paid.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_attribution_id')->constrained()->cascadeOnDelete();
            $table->string('booking_reference')->nullable();
            $table->unsignedTinyInteger('booking_sequence_number');
            $table->decimal('share_percentage_applied', 5, 2);
            $table->decimal('estimated_amount_eur', 10, 2)->nullable();
            $table->string('status')->default('pending'); // pending | reconciled | paid
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_shares');
    }
};
