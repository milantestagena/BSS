<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A partner can run more than one code (different campaigns/channels) — see CLAUDE.md
// section 6. `code` is what shows up in ?ref= on the wizard URL.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_partner_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('label')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_codes');
    }
};
