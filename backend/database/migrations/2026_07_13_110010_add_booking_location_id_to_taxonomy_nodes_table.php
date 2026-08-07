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
        Schema::table('taxonomy_nodes', function (Blueprint $table) {
            // Nullable on purpose — most taxonomy nodes won't have a matched Booking location
            // yet (we're seeding fake test IDs now, real ones once affiliate/API access exists).
            // Lives here, not on `locations`, because the cardinality is "one of our nodes maps
            // to at most one Booking location", while most Booking locations never get a node.
            $table->foreignId('booking_location_id')->nullable()->after('parent_id')
                ->constrained('locations')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('taxonomy_nodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_location_id');
        });
    }
};
