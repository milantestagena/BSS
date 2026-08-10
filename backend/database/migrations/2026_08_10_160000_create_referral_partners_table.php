<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// See CLAUDE.md section 6 — influencer affiliate program, architecturally and conceptually
// separate from the user-to-user credit referral (User.referral_source). Partners are
// manually onboarded (no self-signup yet), so `password` is set by an admin/invite flow, not
// registration. `share_percentage` is per-partner negotiable (not a global constant) — the
// launch-incentive case (first partner gets up to 100% on first booking) is just a higher
// value here, not a separate code path.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->decimal('share_percentage', 5, 2)->default(50.00);
            $table->string('status')->default('active'); // active | inactive
            $table->text('notes')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_partners');
    }
};
