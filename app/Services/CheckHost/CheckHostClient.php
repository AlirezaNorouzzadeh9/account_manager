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

    /** node hostname => English city */
    protected const IRAN_NODES = [
        'ir2.node.check-host.net' => 'Isfahan',
        'ir3.node.check-host.net' => 'Shiraz',
        'ir4.node.check-host.net' => 'Shiraz',
        'ir5.node.check-host.net' => 'Tehran',
        'ir6.node.check-host.net' => 'Qom',
        'ir7.node.check-host.net' => 'Tehran',
        'ir8.node.check-host.net' => 'Tehran',
        'ir9.node.check-host.net' => 'Khonj',
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
            $city = self::IRAN_NODES[$node] ?? $node;

            $total = count($samples);
            $success = count($ok);
            $status = $success > 0 ? '✅' : '❌';

            if ($success === 0) {
                // $total can be 0 too (check-host's own probe never got a
                // sample at all, not just failed ones) — showing "0/0"
                // there reads as broken, so the ratio is only shown once
                // there's an actual ratio to report.
                $ratio = $total > 0 ? "{$success}/{$total} " : '';
                $lines[] = "{$status} 🇮🇷 Iran, {$city} {$ratio}- no response";
                continue;
            }

            $avgMs = (int) round((array_sum(array_column($ok, 1)) / $success) * 1000);
            $lines[] = "{$status} 🇮🇷 Iran, {$city} {$success}/{$total} - {$avgMs}ms";
        }

        return $lines === [] ? 'No ping result received.' : implode("\n", $lines);
    }

    /**
     * @param array $data as returned by getResult()
     * True if the evaluated nodes are clean enough to call it healthy. A
     * node with ZERO samples ("no response") means check-host's own probe
     * never reached a verdict — that's a problem on their end, not evidence
     * our server is unreachable — so it's skipped entirely rather than
     * counted as a failure. Among the nodes that DID get samples, up to ONE
     * failing node is tolerated WHEN 2+ nodes got evaluated: a single
     * check-host probe having a bad measurement window (while every other
     * node — often 7 of 8 — is fully clean) is common network noise on
     * their end, not a real outage, and treating it as one was causing
     * false-positive down-alerts/failovers. With only one node evaluated
     * (or two-plus real failures), any failure still counts as a genuine
     * problem — tolerance only kicks in with enough nodes to make "one bad
     * probe" a meaningfully small fraction.
     */
    public function allNodesOk(array $data): bool
    {
        $evaluated = 0;
        $failed = 0;

        foreach ($data as $pings) {
            $samples = $pings[0] ?? [];

            if (empty($samples)) {
                continue;
            }

            $evaluated++;
            $ok = array_filter($samples, fn ($s) => ($s[0] ?? null) === 'OK');

            if (empty($ok)) {
                $failed++;
            }
        }

        if ($evaluated === 0) {
            return false;
        }

        return $evaluated >= 2 ? $failed <= 1 : $failed === 0;
    }

    /**
     * @param array $data as returned by getResult()
     * How many of the probed Iran nodes had at least one successful ping —
     * used to compare two candidate servers and keep whichever is closer to
     * a fully clean ping when neither one is perfect (see
     * CreateServerFinalReportJob/ReplaceServerPingCheckJob's "keep the best
     * of two" retry logic).
     */
    public function okNodeCount(array $data): int
    {
        $count = 0;

        foreach ($data as $pings) {
            $samples = $pings[0] ?? [];

            if (! empty(array_filter($samples, fn ($s) => ($s[0] ?? null) === 'OK'))) {
                $count++;
            }
        }

        return $count;
    }
}
