<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Server-side proxy for OSM Nominatim /search. Reasons we don't call it
 * directly from the browser:
 *   1. Nominatim's usage policy requires a meaningful User-Agent + contact
 *      — harder to guarantee from a browser extension'd tab.
 *   2. We want to cache results so Secretaire isn't hammering the public
 *      endpoint on every keystroke, and so repeat queries are instant.
 *   3. Browser CORS would be flakier.
 *
 * Response is a normalized array of suggestions:
 *   [{ formatted, street, city, state, postal_code, country, lat, lon }].
 */
final class AddressAutocompleteController extends Controller
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        if (mb_strlen($query) < 3) {
            return response()->json(['results' => []]);
        }

        // Cache normalized results for 24h — address strings don't shift often,
        // and this drops the load on Nominatim's free endpoint while making
        // repeat sessions instantaneous.
        $cacheKey = 'addr:nominatim:'.sha1(strtolower($query));
        $results = Cache::remember($cacheKey, now()->addDay(), fn () => $this->fetch($query));

        return response()->json(['results' => $results]);
    }

    /**
     * @return array<int, array{formatted: string, street: ?string, city: ?string, state: ?string, postal_code: ?string, country: ?string, lat: ?string, lon: ?string}>
     */
    private function fetch(string $query): array
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    // Nominatim's policy asks for a descriptive UA that
                    // identifies the app + a reachable contact point.
                    'User-Agent' => 'Secretaire/1.0 ('.(config('app.url') ?: 'secretaire.aurnata.com').')',
                    'Accept-Language' => 'en',
                ])
                ->get(self::NOMINATIM_URL, [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => 8,
                ]);
        } catch (\Throwable) {
            return [];
        }
        if (! $response->successful()) {
            return [];
        }

        $out = [];
        foreach ((array) $response->json() as $row) {
            if (! is_array($row)) {
                continue;
            }
            $addr = is_array($row['address'] ?? null) ? $row['address'] : [];
            $road = self::shortDirectional((string) ($addr['road'] ?? ''));
            $street = trim(((string) ($addr['house_number'] ?? '')).' '.$road);

            $city = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['hamlet'] ?? null;
            $state = $addr['state'] ?? $addr['province'] ?? $addr['region'] ?? null;
            $postal = $addr['postcode'] ?? null;

            // Build our own "formatted" head-first: "123 SE Main St ·
            // Portland, OR 97202". Nominatim's display_name is verbose
            // (county, country) and frequently missing the house number
            // at the head; recomposing gives predictable pick rows.
            $cityLine = trim(implode(' ', array_filter([
                $city ? rtrim((string) $city, ',').',' : null,
                $state,
                $postal,
            ])));
            $formatted = trim($street.($cityLine !== '' ? ' · '.$cityLine : ''));

            $out[] = [
                'formatted' => $formatted !== '' ? $formatted : (string) ($row['display_name'] ?? ''),
                'street' => $street !== '' ? $street : null,
                'city' => $city,
                'state' => $state,
                'postal_code' => $postal,
                'country' => $addr['country'] ?? null,
                'lat' => isset($row['lat']) ? (string) $row['lat'] : null,
                'lon' => isset($row['lon']) ? (string) $row['lon'] : null,
            ];
        }

        return $out;
    }

    /**
     * Collapse US directional prefixes + generics to the USPS abbreviations
     * people actually say out loud. Word-boundary anchored so "Northfield"
     * stays whole. Runs over whichever words appear in the road string.
     */
    private static function shortDirectional(string $road): string
    {
        if ($road === '') {
            return $road;
        }

        $map = [
            'Northeast' => 'NE',
            'Northwest' => 'NW',
            'Southeast' => 'SE',
            'Southwest' => 'SW',
            'North' => 'N',
            'South' => 'S',
            'East' => 'E',
            'West' => 'W',
        ];
        foreach ($map as $long => $short) {
            $road = (string) preg_replace('/\b'.preg_quote($long, '/').'\b/i', $short, $road);
        }

        return $road;
    }
}
