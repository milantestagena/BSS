<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Resolves a public IP to an approximate lat/lng/city — see wizard_architecture memory,
 * 2026-08-04. Owner's explicit call: IP geolocation over `navigator.geolocation`, specifically
 * to avoid the browser permission-dialog friction the latter would add to an impulse-traffic
 * funnel. Uses ipapi.co's free tier (no API key, HTTPS, ~1000 req/day) — city-level accuracy
 * only, which is all this needs (a "which country is this visitor probably in" signal, not
 * navigation).
 */
class IpGeolocationClient
{
    /**
     * Null for private/loopback IPs (always true in local dev — Docker's internal network
     * never has a real public IP to look up) or if the lookup fails for any reason. Never
     * throws — this is a best-effort background signal, not a required step.
     */
    public function locate(string $ip): ?array
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        try {
            $response = Http::timeout(3)->get("https://ipapi.co/{$ip}/json/");
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        if (! empty($data['error']) || ! isset($data['latitude'], $data['longitude'])) {
            return null;
        }

        return [
            'lat' => (float) $data['latitude'],
            'lng' => (float) $data['longitude'],
            'city' => $data['city'] ?? null,
            'country_code' => $data['country_code'] ?? null,
        ];
    }
}
