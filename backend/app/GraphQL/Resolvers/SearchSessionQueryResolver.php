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

        return [
            'bookingParams' => $compiler->toBookingParams(),
            'honestReportSignals' => $compiler->toHonestReportSignals(),
            'bookingUrl' => $compiler->toBookingUrl(),
            'bookingFlightsUrl' => $compiler->toBookingFlightsUrl(),
        ];
    }
}
