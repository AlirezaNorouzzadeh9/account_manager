<?php

namespace App\Services\Providers;

use App\Jobs\CreateServerReadyJob;
use App\Models\Panel;
use App\Models\ServerSecret;
use Illuminate\Support\Str;

/**
 * Shared "actually create a droplet" logic used both by the normal
 * create-server flow and by "rebuild with a new IP" (which deletes a
 * server and recreates it from its previously saved spec).
 */
class ServerProvisioningService
{
    public function create(Panel $panel, string $hostname, string $region, string $size, string $image, int $chatId): void
    {
        $password = Str::password(20, symbols: false);
        $userData = "#cloud-config\nchpasswd:\n  expire: false\n  list: |\n    root:{$password}\nssh_pwauth: true\n";

        $result = ProviderManager::forPanel($panel)->createServer([
            'name' => $hostname,
            'region' => $region,
            'size' => $size,
            'image' => $image,
            'monitoring' => true,
            'ipv6' => true,
            'user_data' => $userData,
        ]);

        $actionId = $result['links']['actions'][0]['id'] ?? null;
        $serverId = $result['droplet']['id'] ?? null;
        $credentials = "👤 کاربر: root\n🔑 رمز عبور: {$password}";

        if ($serverId) {
            ServerSecret::updateOrCreate(
                ['panel_id' => $panel->id, 'provider_server_id' => $serverId],
                [
                    'root_password' => $password,
                    'region' => $region,
                    'size' => $size,
                    'image' => $image,
                    'hostname' => $hostname,
                ]
            );
        }

        if ($actionId) {
            CreateServerReadyJob::dispatch($panel->id, $actionId, $chatId, $hostname, $credentials);
        }
    }
}
