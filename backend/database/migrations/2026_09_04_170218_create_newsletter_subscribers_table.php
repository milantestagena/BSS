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
        // Owner's ask, 2026-09-04 ("subskribujte, da vam javimo kad imamo sledecu lepu
        // ponudu") — capture only, no send pipeline exists yet (that's a real future feature,
        // not built tonight). unique(email) so a repeat submit is just a no-op, not a duplicate
        // row — see subscribeToNewsletter's updateOrCreate.
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};
