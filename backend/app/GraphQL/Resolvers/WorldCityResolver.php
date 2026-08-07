<?php

namespace App\GraphQL\Resolvers;

use App\Models\TaxonomyNode;
use App\Models\WorldCity;
use App\Services\IpGeolocationClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Powers the home_city typeahead — see wizard_architecture memory, 2026-08-03. `world_cities`
 * (GeoNames import) is deliberately separate from `taxonomy_nodes` (see the migration comment),
 * so picking a search result needs a bridge back into the taxonomy tree that `home_city_id`
 * (search_sessions FK) actually points at — see selectAsHomeCity().
 */
class WorldCityResolver
{
    /**
     * Prefix-matches ascii_name (case-insensitive), ranked by population so major cities
     * surface first for an ambiguous query. Capped at 10 — this is a typeahead, not a browse
     * list. Requires 3+ characters, same threshold the frontend debounces on.
     */
    public function search($_, array $args): Collection
    {
        $query = trim($args['query']);
        if (mb_strlen($query) < 3) {
            return collect();
        }

        return WorldCity::query()
            ->where(function (Builder $builder) use ($query) {
                $builder->where('ascii_name', 'ILIKE', "{$query}%")
                    ->orWhere('name', 'ILIKE', "{$query}%");
            })
            ->orderByDesc('population')
            ->limit(10)
            ->get();
    }

    /**
     * Find-or-create the taxonomy_node this WorldCity corresponds to, so home_city_id keeps
     * pointing at a real taxonomy_nodes row — reuses the EXISTING distanceKmTo/
     * distanceFromHomeKm machinery unchanged, rather than teaching it a second location shape.
     * Matched by `meta.geoname_id` (stable external key), not name — names collide across
     * countries, geoname_id never does. `type` is deliberately `home_city_reference`, not
     * `city` — these aren't curated destination content (no atmosphere/drinks/food tags, no
     * climate, no cultural_availability), just a coordinate anchor for the distance
     * calculation, and mixing them into the `city` type would pollute destination suggestion
     * queries (GeographyResolver's `type=city` results) with places nobody would ever travel TO
     * as a swim destination.
     */
    public function selectAsHomeCity($_, array $args): TaxonomyNode
    {
        $worldCity = WorldCity::findOrFail($args['worldCityId']);

        return $this->bridgeToTaxonomyNode($worldCity);
    }

    /**
     * Auto-detects the visitor's home city from their request IP instead of asking — owner's
     * explicit call, 2026-08-04 ("izbacujemo ovo pitanje, pokupi iz browsera" -> IP
     * geolocation, chosen over navigator.geolocation to avoid its permission-dialog friction
     * on an impulse-traffic funnel). Returns null (never throws) if the IP can't be
     * geolocated — private/loopback IP (always true in local dev), the lookup service being
     * down, or no world_cities row existing at all. Frontend treats null exactly like "user
     * never answered this question" — home_city_id just stays unset, same as it always could.
     */
    public function detectHomeCity($_, array $args): ?TaxonomyNode
    {
        $location = (new IpGeolocationClient)->locate(request()->ip());
        if ($location === null) {
            return null;
        }

        $nearest = $this->nearestWorldCity($location['lat'], $location['lng']);
        if ($nearest === null) {
            return null;
        }

        return $this->bridgeToTaxonomyNode($nearest);
    }

    /**
     * Nearest world_cities row by great-circle distance to the given coordinates — plain SQL
     * haversine over all ~34k rows (not a hot path, once per session, no index needed).
     * least/greatest clamp guards against acos() domain errors from floating-point rounding
     * pushing marginally outside [-1, 1] (e.g. when the coordinates are nearly identical).
     */
    private function nearestWorldCity(float $lat, float $lng): ?WorldCity
    {
        return WorldCity::query()
            ->selectRaw(
                '*, (6371 * acos(least(1, greatest(-1,
                    cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?))
                    + sin(radians(?)) * sin(radians(lat))
                )))) as distance_km',
                [$lat, $lng, $lat]
            )
            ->orderBy('distance_km')
            ->first();
    }

    /**
     * Find-or-create the taxonomy_node a WorldCity corresponds to, so home_city_id keeps
     * pointing at a real taxonomy_nodes row — reuses the EXISTING distanceKmTo/
     * distanceFromHomeKm machinery unchanged, rather than teaching it a second location shape.
     * Matched by `meta.geoname_id` (stable external key), not name — names collide across
     * countries, geoname_id never does. `type` is deliberately `home_city_reference`, not
     * `city` — these aren't curated destination content (no atmosphere/drinks/food tags, no
     * climate, no cultural_availability), just a coordinate anchor for the distance
     * calculation, and mixing them into the `city` type would pollute destination suggestion
     * queries (GeographyResolver's `type=city` results) with places nobody would ever travel TO
     * as a swim destination.
     */
    private function bridgeToTaxonomyNode(WorldCity $worldCity): TaxonomyNode
    {
        $existing = TaxonomyNode::where('type', 'home_city_reference')
            ->where('meta->geoname_id', $worldCity->geoname_id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return TaxonomyNode::create([
            'type' => 'home_city_reference',
            'slug' => 'geoname-'.$worldCity->geoname_id,
            'label' => "{$worldCity->name}, {$worldCity->country_code}",
            'sort_order' => 0,
            'meta' => [
                'geoname_id' => $worldCity->geoname_id,
                'lat' => $worldCity->lat,
                'lng' => $worldCity->lng,
                'country_code' => $worldCity->country_code,
            ],
        ]);
    }
}
