<?php

namespace App\Services\CheckHost;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the (unauthenticated, public) check-host.net ping API,
 * scoped to its Iranian probe nodes — used to show latency from Iran to a
 * freshly created server.
 */
class CheckHostClient
{
    protected const BASE_URL = 'https://check-host.net';

    /** node hostname => [English city, Persian city] */
    protected const IRAN_NODES = [
        'ir2.node.check-host.net' => ['Isfahan', 'اصفهان'],
        'ir5.node.check-host.net' => ['Tehran', 'تهران'],
        'ir7.node.check-host.net' => ['Tehran', 'تهران'],
        'ir8.node.check-host.net' => ['Tehran', 'تهران'],
        'ir9.node.check-host.net' => ['Khonj', 'خنج'],
    ];

    /**
     * Starts an async ping check and returns its request id.
     */
    public function requestPing(string $host): string
    {
        // check-host.net expects the "node" query key repeated as plain
        // node=a&node=b (not PHP's node[]=a&node[]=b), so build it by hand.
        $query = http_build_query(['host' => $host]);

        foreach (array_keys(self::IRAN_NODES) as $node) {
            $query .= '&node='.urlencode($node);
        }

        $response = Http::acceptJson()->timeout(15)->get(self::BASE_URL.'/check-ping?'.$query);

        if ($response->failed() || ! $response->json('ok')) {
            throw new RuntimeException('درخواست پینگ check-host.net ناموفق بود.');
        }

        return (string) $response->json('request_id');
    }

    /**
     * Fetches the ping result, or null while any probed node is still pending.
     */
    public function getResult(string $requestId): ?array
    {
        $response = Http::acceptJson()->timeout(15)->get(self::BASE_URL."/check-result/{$requestId}");

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();

        if (empty($data) || in_array(null, $data, true)) {
            return null;
        }

        return $data;
    }

    /**
     * @param array $data as returned by getResult()
     */
    public function formatResult(array $data): string
    {
        $lines = [];

        foreach ($data as $node => $pings) {
            $samples = $pings[0] ?? [];
            $ok = array_values(array_filter($samples, fn ($s) => ($s[0] ?? null) === 'OK'));
            [$en, $fa] = self::IRAN_NODES[$node] ?? [$node, $node];

            $total = count($samples);
            $success = count($ok);
            $status = $success > 0 ? '✅' : '❌';
            $ratio = "{$success}/{$total}";

            if ($success === 0) {
                $lines[] = "{$status} 🇮🇷 Iran, {$en} ({$fa}) — {$ratio} — no response";
                continue;
            }

            $avgMs = (int) round((array_sum(array_column($ok, 1)) / $success) * 1000);
            $lines[] = "{$status} 🇮🇷 Iran, {$en} ({$fa}) — {$ratio} — {$avgMs}ms";
        }

        return $lines === [] ? 'No ping result received.' : implode("\n", $lines);
    }
}
