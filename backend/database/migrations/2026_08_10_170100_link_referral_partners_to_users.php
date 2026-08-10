<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner's call, 2026-08-10 (same day as the original table): a reseller is an EXISTING
// customer User the admin promotes from the Users list, not a separate manually-created
// identity with its own password — they log in the same "Sign in with Google" way as every
// other customer. Drops the now-redundant name/email/password/remember_token columns (that
// data already lives on User) and links via user_id instead. Safe to alter directly rather
// than layering a follow-up migration around it — this table has zero real rows in any
// environment (referral partner onboarding hasn't started yet).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_partners', function (Blueprint $table) {
            $table->dropColumn(['name', 'email', 'password', 'remember_token']);
            $table->foreignId('user_id')->unique()->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('referral_partners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->string('name')->default('');
            $table->string('email')->unique()->default('');
            $table->string('password')->nullable();
            $table->rememberToken();
        });
    }
};
