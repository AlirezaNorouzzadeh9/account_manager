<?php

namespace App\Services\Dns;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around Cloudflare's DNS records API — used to point a fresh
 * node's subdomain (e.g. "srv-1.node.pcbot.top") at its own IP right after
 * creation, so the PasarGuard panel can connect to the node by domain name
 * instead of by raw IP (see config/dns.php for why that matters).
 */
class CloudflareDnsClient
{
    protected const BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        protected string $apiToken,
        protected string $zoneId,
    ) {
    }

    protected function http()
    {
        return Http::withToken($this->apiToken)->acceptJson()->timeout(15);
    }

    /**
     * Creates the A record if it doesn't exist yet, or updates it in place
     * if it does (e.g. a server was recreated with a new IP).
     */
    public function upsertARecord(string $name, string $ip): void
    {
        $existing = $this->http()
            ->get(self::BASE_URL."/zones/{$this->zoneId}/dns_records", ['type' => 'A', 'name' => $name])
            ->throw()
            ->json('result') ?? [];

        $recordId = $existing[0]['id'] ?? null;

        $response = $recordId
            ? $this->http()->patch(self::BASE_URL."/zones/{$this->zoneId}/dns_records/{$recordId}", ['content' => $ip])
            : $this->http()->post(self::BASE_URL."/zones/{$this->zoneId}/dns_records", [
                'type' => 'A',
                'name' => $name,
                'content' => $ip,
                'ttl' => 300,
                'proxied' => false,
            ]);

        if ($response->failed() || ! $response->json('success')) {
            $errors = collect($response->json('errors'))->pluck('message')->implode(', ');

            throw new RuntimeException("درخواست Cloudflare ناموفق بود: {$errors}");
        }
    }
}
