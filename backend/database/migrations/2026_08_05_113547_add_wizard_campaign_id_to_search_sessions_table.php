<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A session started via startCampaign() never recorded WHICH campaign it came from —
     * needed now (2026-08-05) so SearchSessionQueryCompiler/BudgetEstimationEngine can look up
     * that campaign's WizardCampaignDestinationPrice sheet. Nullable — the plain '' route's
     * generic (non-campaign) sessions have none, and that's a real, valid state, not missing
     * data.
     */
    public function up(): void
    {
        Schema::table('search_sessions', function (Blueprint $table) {
            $table->foreignId('wizard_campaign_id')->nullable()->after('status')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('search_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wizard_campaign_id');
        });
    }
};
