<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Trusts X-Forwarded-* only from proxies listed in config('nasery.trusted_proxies')
 * (env TRUSTED_PROXIES), read per request so it works with config:cache.
 *
 * Without it, $request->ip() behind Vercel/Railway is the proxy's address and
 * every user would share one rate-limit bucket. With it wrongly set to trust
 * everyone on a directly reachable server, clients could spoof their IP.
 */
class TrustConfiguredProxies extends TrustProxies
{
    protected function proxies()
    {
        $proxies = trim((string) config('nasery.trusted_proxies', ''));

        return match ($proxies) {
            '' => null,
            '*', '**' => $proxies,
            default => array_values(array_filter(array_map('trim', explode(',', $proxies)))),
        };
    }
}
