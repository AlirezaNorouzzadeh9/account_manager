<?php

namespace App\Services\Providers;

use App\Enums\Provider;
use App\Models\Panel;
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
    ];

    public static function forPanel(Panel $panel): ProviderClient
    {
        return self::make($panel->provider, $panel->api_token);
    }

    public static function make(Provider $provider, string $apiToken): ProviderClient
    {
        $class = self::$clients[$provider->value] ?? null;

        if ($class === null) {
            throw new ProviderException("پشتیبانی از {$provider->label()} هنوز اضافه نشده است.");
        }

        return new $class($apiToken);
    }
}
