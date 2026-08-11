<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// User-to-user CREDIT referral — deliberately separate from the influencer/affiliate MONEY
// system (ReferralPartner/ReferralCode/ReferralAttribution/CommissionShare, CLAUDE.md section
// 6: "moraju ostati arhitektonski i konceptualno odvojeni"). No new tables needed: every user
// is implicitly their own referrer via a `u<id>` share link (see GoogleAuthController), first-
// touch locked at signup like everything else referral-related. `nullOnDelete` (not cascade) —
// deleting the referrer shouldn't delete the user they referred.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('referred_by_user_id')->nullable()->after('referral_source')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_user_id');
        });
    }
};
