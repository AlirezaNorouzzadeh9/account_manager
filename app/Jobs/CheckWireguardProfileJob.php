<?php

namespace App\Jobs;

use App\Models\WireguardProfile;
use App\Services\CheckHost\CheckHostClient;
use App\Services\Dns\DnsResolver;
use App\Services\Pasarguard\PasarguardNodeInstaller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Domain-centric health check for one WireGuard profile (see
 * wireguard:check-profiles, run every 10 minutes): resolves the profile's
 * OWN domain (e.g. "server3.node.pcbot.top") to whatever IP it currently
 * points at and pings THAT from Iran — independent of whether any server in
 * this bot is tracking that IP at all (unlike CheckServerPingJob, which is
 * scoped to a specific ServerSecret).
 *
 * On a real failure, repoints the domain at another of the same admin's
 * profiles whose own domain is currently resolving to a healthy IP, so
 * users following the domain reconnect there instead of dropping. Alerts
 * once per ongoing problem via WireguardProfile::ping_alerted, same pattern
 * as CheckServerPingJob's ServerSecret::ping_alerted.
 */
class CheckWireguardProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(protected int $profileId)
    {
    }

    public function handle(CheckHostClient $checkHost, DnsResolver $dns, Nutgram $bot): void
    {
        $profile = WireguardProfile::find($this->profileId);

        if (! $profile) {
            return;
        }

        $domain = $this->domainFor($profile->name);
        $ip = $dns->resolve($domain);

        if (! $ip) {
            return; // domain doesn't resolve to anything yet — nothing to check
        }

        try {
            $requestId = $checkHost->requestPing($ip);
        } catch (Throwable) {
            return; // check-host itself being unreachable isn't a server problem
        }

        $result = null;

        for ($i = 0; $i < 10; $i++) {
            $result = $checkHost->getResult($requestId);

            if ($result !== null) {
                break;
            }

            sleep(3);
        }

        // No result at all (check-host hiccup) => nothing to report either way.
        if ($result === null) {
            return;
        }

        if ($checkHost->allNodesOk($result)) {
            if ($profile->ping_alerted) {
                $profile->update(['ping_alerted' => false]);
            }

            return;
        }

        // Already alerted for this ongoing problem — stay silent until it
        // clears (handled above) instead of re-sending every 10 minutes.
        if ($profile->ping_alerted) {
            return;
        }

        $profile->update(['ping_alerted' => true]);

        $bot->sendMessage(
            "⚠️ دامنه‌ی پروفایل «{$profile->name}» ({$domain} ← {$ip}) از ایران مشکل دارد:\n".
            $checkHost->formatResult($result),
            chat_id: $profile->created_by,
        );

        $this->failover($profile, $domain, $dns, $bot);
    }

    /**
     * Best-effort: the first sibling profile (same admin) whose OWN domain
     * currently resolves and whose last check was clean gets the down
     * profile's domain repointed at it. No new server is created here.
     */
    protected function failover(WireguardProfile $downProfile, string $downDomain, DnsResolver $dns, Nutgram $bot): void
    {
        $siblings = WireguardProfile::ownedBy($downProfile->created_by)
            ->where('id', '!=', $downProfile->id)
            ->where('ping_alerted', false)
            ->get();

        foreach ($siblings as $sibling) {
            $ip = $dns->resolve($this->domainFor($sibling->name));

            if (! $ip) {
                continue;
            }

            $result = (new PasarguardNodeInstaller())->syncProfileDns($downProfile->name, $ip);

            if ($result && ! $result['error']) {
                $bot->sendMessage(
                    "🔀 دامنه‌ی «{$downDomain}» موقتاً به IP پروفایل «{$sibling->name}» ({$ip}) سوییچ شد تا کاربران قطع نشوند.",
                    chat_id: $downProfile->created_by,
                );
            }

            return;
        }
    }

    protected function domainFor(string $profileName): string
    {
        return "{$profileName}.".config('dns.cloudflare.node_domain');
    }
}
