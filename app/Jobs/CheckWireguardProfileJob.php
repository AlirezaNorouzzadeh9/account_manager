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
 * wireguard:check-profiles, run every 10 minutes): pings the profile's OWN
 * home IP (WireguardProfile::own_ip, set whenever a node is installed for
 * it) from Iran — independent of whether any server in this bot is tracking
 * that IP at all (unlike CheckServerPingJob, which is scoped to a specific
 * ServerSecret). Deliberately pings own_ip rather than resolving the
 * profile's domain live: once failed over, the domain points at a
 * borrowed sibling IP, and resolving it would just re-check the sibling
 * instead of noticing when the real server comes back.
 *
 * On a real failure, repoints the domain at another of the same admin's
 * profiles' own (healthy) IP, so users following the domain reconnect there
 * instead of dropping. Once the down profile's own IP is healthy again, the
 * domain is repointed back to it — ending the borrow. Alerts once per
 * ongoing problem via WireguardProfile::ping_alerted, same pattern as
 * CheckServerPingJob's ServerSecret::ping_alerted.
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
        // Older profiles set up before own_ip existed fall back to whatever
        // the domain currently resolves to (accurate as long as they've
        // never been failed over).
        $ip = $profile->own_ip ?? $dns->resolve($domain);

        if (! $ip) {
            return; // no known IP for this profile yet — nothing to check
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
                $this->recover($profile, $domain, $ip, $bot);
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
            "⚠️ سرور پروفایل «{$profile->name}» ({$ip}) از ایران مشکل دارد:\n".
            $checkHost->formatResult($result),
            chat_id: $profile->created_by,
        );

        $this->failover($profile, $domain, $dns, $bot);
    }

    /**
     * The profile's own server is healthy again — move the domain back to
     * it (ending any temporary borrow) and clear the alert.
     */
    protected function recover(WireguardProfile $profile, string $domain, string $ip, Nutgram $bot): void
    {
        $profile->update(['ping_alerted' => false]);

        $dnsResult = (new PasarguardNodeInstaller())->syncProfileDns($profile->name, $ip);

        if ($dnsResult && ! $dnsResult['error']) {
            $bot->sendMessage(
                "✅ سرور پروفایل «{$profile->name}» دوباره سالم شد؛ دامنه‌ی «{$domain}» به IP اصلی خودش ({$ip}) برگشت.",
                chat_id: $profile->created_by,
            );
        }
    }

    /**
     * Best-effort: the first sibling profile (same admin) whose OWN ip is
     * known and whose last check was clean gets the down profile's domain
     * repointed at it. No new server is created here.
     */
    protected function failover(WireguardProfile $downProfile, string $downDomain, DnsResolver $dns, Nutgram $bot): void
    {
        $siblings = WireguardProfile::ownedBy($downProfile->created_by)
            ->where('id', '!=', $downProfile->id)
            ->where('ping_alerted', false)
            ->get();

        foreach ($siblings as $sibling) {
            $ip = $sibling->own_ip ?? $dns->resolve($this->domainFor($sibling->name));

            if (! $ip) {
                continue;
            }

            $result = (new PasarguardNodeInstaller())->syncProfileDns($downProfile->name, $ip);

            if ($result && ! $result['error']) {
                $bot->sendMessage(
                    "🔀 دامنه‌ی «{$downDomain}» موقتاً به IP پروفایل «{$sibling->name}» ({$ip}) سوییچ شد تا کاربران قطع نشوند؛ به‌محض سالم شدن سرور اصلی دوباره برمی‌گردد.",
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
