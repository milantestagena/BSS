<?php

namespace App\GraphQL\Resolvers;

use App\Models\DestinationGuide;
use App\Models\SearchSession;

class DestinationGuideResolver
{
    /**
     * Thin by design, same pattern as SearchSessionQueryResolver::compiled — campaign context
     * is derived from the session, not passed as a separate arg, matching every other
     * per-session query in this schema.
     */
    public function show($_, array $args): ?DestinationGuide
    {
        $session = SearchSession::findOrFail($args['sessionId']);
        if (! $session->wizard_campaign_id) {
            return null;
        }

        return DestinationGuide::where('wizard_campaign_id', $session->wizard_campaign_id)
            ->where('taxonomy_node_id', $args['taxonomyNodeId'])
            ->first();
    }
}
