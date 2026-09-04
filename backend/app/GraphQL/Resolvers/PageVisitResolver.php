<?php

namespace App\GraphQL\Resolvers;

use App\Models\PageVisit;
use App\Services\IpGeolocationClient;

class PageVisitResolver
{
    /**
     * Fire-and-forget from the frontend root component, once per app load — see App.ngOnInit.
     * The request IP is only ever used in-memory here to resolve country/city (same
     * IpGeolocationClient WorldCityResolver::detectHomeCity already uses) and is never
     * persisted. Never throws — a failed geolocation lookup still logs the visit with a null
     * location rather than losing the count entirely.
     */
    public function record($_, array $args): bool
    {
        $location = (new IpGeolocationClient)->locate(request()->ip());

        PageVisit::create([
            'path' => $args['path'],
            'country' => $location['country_code'] ?? null,
            'city' => $location['city'] ?? null,
        ]);

        return true;
    }
}
