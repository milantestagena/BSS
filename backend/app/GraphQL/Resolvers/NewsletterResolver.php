<?php

namespace App\GraphQL\Resolvers;

use App\Models\NewsletterSubscriber;

class NewsletterResolver
{
    /**
     * Idempotent on purpose — a repeat submit of the same email (double-click, re-visit) is a
     * silent no-op, not a duplicate row or an error the frontend has to handle specially.
     */
    public function subscribe($_, array $args): bool
    {
        NewsletterSubscriber::updateOrCreate(['email' => trim(strtolower($args['email']))]);

        return true;
    }
}
