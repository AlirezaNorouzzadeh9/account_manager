<?php

namespace App\Telegram\Support;

/**
 * The OS picker (creating a server, rebuilding one) only ever offers Ubuntu —
 * every server this bot manages ends up running Docker/WireGuard the same
 * way, so the other distros a provider's image list returns are just noise.
 * Filters by label text rather than a provider-specific "distribution"/
 * "vendor" field since not every ProviderClient::images() keeps that field
 * on the mapped result (see VultrClient/LinodeClient).
 */
trait FiltersUbuntuImages
{
    protected function onlyUbuntu(array $images): array
    {
        return array_values(array_filter(
            $images,
            fn (array $image) => str_contains(strtolower($image['label'] ?? ''), 'ubuntu')
        ));
    }
}
