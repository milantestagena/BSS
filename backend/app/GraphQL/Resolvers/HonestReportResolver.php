<?php

namespace App\GraphQL\Resolvers;

use App\Models\SearchSession;
use App\Services\HonestReportGenerator;

class HonestReportResolver
{
    /** @return array{pros: string[], cons: string[], summary: string} */
    public function generate($_, array $args): array
    {
        $session = SearchSession::findOrFail($args['sessionId']);

        return app(HonestReportGenerator::class)->generate($session, [
            'name' => $args['listingName'],
            'description' => $args['listingDescription'],
            'reviews' => $args['reviews'] ?? [],
        ]);
    }
}
