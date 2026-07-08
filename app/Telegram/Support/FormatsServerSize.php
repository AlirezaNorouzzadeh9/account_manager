<?php

namespace App\Telegram\Support;

/**
 * Renders a DigitalOcean "size" (plan) API object as a consistent,
 * human-readable label — used everywhere a plan is picked or shown
 * (creating a server, resizing one, viewing its details).
 */
trait FormatsServerSize
{
    /**
     * DigitalOcean's Basic plans encode CPU vendor as a "-amd"/"-intel" slug
     * suffix (e.g. "s-1vcpu-2gb-amd") rather than a separate field, with no
     * suffix at all on the older/regular tier.
     */
    protected function formatSizeLabel(array $s): string
    {
        $vendor = match (true) {
            str_ends_with($s['slug'], '-amd') => ' (AMD)',
            str_ends_with($s['slug'], '-intel') => ' (Intel)',
            default => '',
        };

        $ram = $s['memory'] % 1024 === 0 ? ($s['memory'] / 1024).'GB' : $s['memory'].'MB';
        $price = rtrim(rtrim(number_format((float) $s['price_monthly'], 2, '.', ''), '0'), '.');

        return "{$s['vcpus']} CPU{$vendor} | {$ram} RAM | {$price}$";
    }
}
