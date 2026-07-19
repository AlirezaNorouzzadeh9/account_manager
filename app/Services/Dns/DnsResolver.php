<?php

namespace App\Services\Dns;

/**
 * Thin wrapper around dns_get_record() — pulled out into its own injectable
 * class (rather than called directly) purely so tests can swap in a fake
 * resolver; a real DNS lookup can't be intercepted by Http::fake().
 */
class DnsResolver
{
    public function resolve(string $domain): ?string
    {
        $records = @dns_get_record($domain, DNS_A);

        return $records[0]['ip'] ?? null;
    }
}
