<?php

namespace App\Services\Providers;

use App\Enums\Provider;
use App\Models\Panel;
use App\Services\Providers\Azure\AzureClient;
use App\Services\Providers\DigitalOcean\DigitalOceanClient;
use App\Services\Providers\Linode\LinodeClient;
use App\Services\Providers\Ovh\OvhClient;
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
        'ovh' => OvhClient::class,
    ];

    public static function forPanel(Panel $panel): ProviderClient
    {
        return self::make($panel->provider, $panel->api_token, $panel->meta ?? []);
    }

    /**
     * $extra carries the non-secret credentials every simple-bearer-token
     * provider ignores — stored in Panel::$meta since Azure/OVH's auth needs
     * more than one secret:
     * Azure: tenant_id/client_id/subscription_id/resource_group.
     * OVH: application_key/application_secret/service_name ($apiToken is
     * the Consumer Key, OVH's actual account-level, revocable secret).
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

        if ($provider === Provider::Ovh) {
            return new $class(
                $apiToken,
                $extra['application_key'] ?? '',
                $extra['application_secret'] ?? '',
                $extra['service_name'] ?? '',
            );
        }

        return new $class($apiToken);
    }
}
