<?php

namespace Tests\Unit;

use App\Models\CommissionShare;
use App\Models\ReferralPartner;
use Tests\TestCase;

/**
 * Covers CommissionShare::decayTierPercentage() — see CLAUDE.md section 6. Pure attribute
 * reads, no persistence needed, so partners are built in-memory rather than via a factory/DB.
 */
class CommissionShareTest extends TestCase
{
    public function test_decay_curve_applies_by_default(): void
    {
        $partner = new ReferralPartner(['share_percentage' => 50, 'decay_enabled' => true]);

        $this->assertSame(50.0, CommissionShare::decayTierPercentage($partner, 1));
        $this->assertSame(15.0, CommissionShare::decayTierPercentage($partner, 2));
        $this->assertSame(5.0, CommissionShare::decayTierPercentage($partner, 3));
        $this->assertSame(0.0, CommissionShare::decayTierPercentage($partner, 4));
    }

    public function test_decay_curve_ignores_the_partners_own_rate_for_tiers_2_and_3(): void
    {
        // Owner's own launch-incentive example, CLAUDE.md section 6: a partner negotiated up to
        // 100% on the first booking still only gets the fixed 15%/5% standard tiers after that.
        $partner = new ReferralPartner(['share_percentage' => 100, 'decay_enabled' => true]);

        $this->assertSame(100.0, CommissionShare::decayTierPercentage($partner, 1));
        $this->assertSame(15.0, CommissionShare::decayTierPercentage($partner, 2));
    }

    public function test_decay_disabled_pays_the_flat_share_percentage_on_every_booking(): void
    {
        // Owner's ask, 2026-08-14 (micro-influencer blogger cohort): "ako mnogo dobrih dovede,
        // mnogo mi je doneo prihod" — no cap, every booking pays the same negotiated rate.
        $partner = new ReferralPartner(['share_percentage' => 50, 'decay_enabled' => false]);

        $this->assertSame(50.0, CommissionShare::decayTierPercentage($partner, 1));
        $this->assertSame(50.0, CommissionShare::decayTierPercentage($partner, 2));
        $this->assertSame(50.0, CommissionShare::decayTierPercentage($partner, 3));
        $this->assertSame(50.0, CommissionShare::decayTierPercentage($partner, 50));
    }
}
