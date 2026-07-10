<?php

namespace App\Telegram\Support;

/**
 * Renders a provider "size" (plan) array as a consistent, human-readable
 * label — used everywhere a plan is picked or shown (creating a server,
 * resizing one, viewing its details).
 */
trait FormatsServerSize
{
    /**
     * A client can set 'label_suffix' on a size (e.g. Linode's plan class —
     * "Shared"/"Dedicated"/"High Memory"/"Premium" — needed to tell apart
     * same-spec plans at different prices) to have it appended here.
     * DigitalOcean doesn't set it: its Basic plans instead encode CPU vendor
     * as a "-amd"/"-intel" slug suffix (e.g. "s-1vcpu-2gb-amd"), detected
     * as a fallback below.
     */
    protected function formatSizeLabel(array $s): string
    {
        $suffix = $s['label_suffix'] ?? match (true) {
            str_ends_with($s['slug'], '-amd') => ' (AMD)',
            str_ends_with($s['slug'], '-intel') => ' (Intel)',
            default => '',
        };

        $ram = $s['memory'] % 1024 === 0 ? ($s['memory'] / 1024).'GB' : $s['memory'].'MB';
        $price = rtrim(rtrim(number_format((float) $s['price_monthly'], 2, '.', ''), '0'), '.');

        return "{$s['vcpus']} CPU{$suffix} | {$ram} RAM | {$price}$";
    }
}
