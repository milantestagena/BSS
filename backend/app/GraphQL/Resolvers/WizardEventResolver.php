<?php

namespace App\GraphQL\Resolvers;

use App\Models\WizardEvent;

class WizardEventResolver
{
    public function record($_, array $args): bool
    {
        WizardEvent::create([
            'search_session_id' => $args['sessionId'],
            'event_type' => $args['eventType'],
            'payload' => $args['payload'] ?? null,
        ]);

        return true;
    }
}
