<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// See CLAUDE.md section 5 — log of every credit change (welcome bonus, booking confirmation,
// AI query spend, manual admin adjustment). `amount` is signed (positive = credit, negative =
// spend) so the wallet balance is always just the sum of this table for a user, for audit.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('type'); // welcome | booking | manual_bonus | ai_query
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
