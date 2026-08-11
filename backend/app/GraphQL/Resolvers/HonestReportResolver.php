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
        $generator = app(HonestReportGenerator::class);

        $report = $generator->generate($session, [
            'name' => $args['listingName'],
            'description' => $args['listingDescription'],
            'reviews' => $args['reviews'] ?? [],
        ]);

        // English is always generated first (canonical, see HonestReportGenerator::translate
        // docblock) — a non-English request gets that SAME report translated, never a
        // separately-generated one.
        $locale = request()->header('X-Locale', 'en');
        if ($locale !== 'en') {
            $report = $generator->translate($report, $locale);
        }

        return $report;
    }
}
