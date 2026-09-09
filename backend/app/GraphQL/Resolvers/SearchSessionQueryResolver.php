<?php

namespace App\GraphQL\Resolvers;

use App\Models\SearchSession;
use App\Services\SearchSessionQueryCompiler;

class SearchSessionQueryResolver
{
    /**
     * Compiles a session into the two shapes the next pipeline stage needs — see
     * SearchSessionQueryCompiler and wizard_architecture memory, 2026-07-30. Thin by design:
     * all the actual logic lives in the service, this just adapts it to GraphQL's ($_, args)
     * resolver signature, same pattern as GeographyResolver::suggested().
     */
    public function compiled($_, array $args): array
    {
        $session = SearchSession::findOrFail($args['sessionId']);
        $compiler = new SearchSessionQueryCompiler($session);

        // Owner's ask, 2026-09-08: which provider a session's booking link actually goes to is a
        // per-CAMPAIGN setting (WizardCampaign::provider()), not a global switch — lets
        // kasno-letovanje (Booking) and Jesenjovanje (Hotels.com) run side by side for a real
        // parallel comparison instead of an all-at-once cutover. A session with no campaign at
        // all (generic flow) or a campaign that hasn't set meta.provider both default to
        // 'booking' — today's only behavior, unchanged. Field name stays `bookingUrl` either way
        // — the frontend only ever treats this as "the redirect link," never cares which
        // provider produced it.
        $bookingUrl = $session->campaign?->provider() === 'hotels_com'
            ? $compiler->toHotelsUrl()
            : $compiler->toBookingUrl();

        return [
            'bookingParams' => $compiler->toBookingParams(),
            'honestReportSignals' => $compiler->toHonestReportSignals(),
            'bookingUrl' => $bookingUrl,
            'bookingFlightsUrl' => $compiler->toBookingFlightsUrl(),
        ];
    }
}
