<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Gates the Filament /admin panel — see AdminPanelProvider / User::canAccessPanel(). Found
// 2026-08-10: User didn't implement FilamentUser at all, so Filament's Authenticate middleware
// fell back to its own default (allow any authenticated 'web' user in `local`, block everyone
// including the real owner in any other environment) — i.e. production silently blocked
// EVERYONE, and local silently let ANY logged-in customer into the admin panel. Neither is
// what anyone wants.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
