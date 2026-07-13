<?php

namespace App\Services\Providers;

use App\Enums\Provider;
use App\Models\Panel;
use App\Services\Providers\Azure\AzureClient;
use App\Services\Providers\DigitalOcean\DigitalOceanClient;
use App\Services\Providers\Linode\LinodeClient;
use App\Services\Providers\Vultr\VultrClient;

class ProviderManager
{
    /**
     * @var array<string, class-string<ProviderClient>>
     */
    protected static array $clients = [
        'digitalocean' => DigitalOceanClient::class,
        'linode' => LinodeClient::class,
        'vultr' => VultrClient::class,
        'azure' => AzureClient::class,
    ];

    public static function forPanel(Panel $panel): ProviderClient
    {
        return self::make($panel->provider, $panel->api_token, $panel->meta ?? []);
    }

    /**
     * $extra carries the non-secret credentials every provider except Azure
     * ignores (tenant_id/client_id/subscription_id/resource_group) — stored
     * in Panel::$meta since Azure's auth needs more than one bearer token.
     */
    public static function make(Provider $provider, string $apiToken, array $extra = []): ProviderClient
    {
        $class = self::$clients[$provider->value] ?? null;

        if ($class === null) {
            throw new ProviderException("پشتیبانی از {$provider->label()} هنوز اضافه نشده است.");
        }

        if ($provider === Provider::Azure) {
            return new $class(
                $apiToken,
                $extra['tenant_id'] ?? '',
                $extra['client_id'] ?? '',
                $extra['subscription_id'] ?? '',
                $extra['resource_group'] ?? '',
            );
        }

        return new $class($apiToken);
    }
}
