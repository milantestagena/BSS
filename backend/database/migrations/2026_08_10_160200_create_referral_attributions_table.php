<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// First-touch attribution, permanently locked per user — see CLAUDE.md section 6. One row per
// user (unique user_id), never overwritten once created. `ip_address` feeds the velocity
// anti-abuse check (many signups from the same IP in a short window get flagged for manual
// review rather than auto-blocked, since a shared IP alone isn't proof of abuse).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('referral_partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referral_code_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address')->nullable();
            $table->boolean('flagged_for_review')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_attributions');
    }
};
