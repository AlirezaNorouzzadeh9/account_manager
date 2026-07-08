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
        [$actionId, , $password] = $this->createSilently($panel, $hostname, $region, $size, $image);
        $credentials = "👤 کاربر: `root`\n🔑 رمز عبور: `{$password}`";

        if ($actionId) {
            CreateServerReadyJob::dispatch($panel->id, $actionId, $chatId, $hostname, $credentials);
        }
    }

    /**
     * Like create(), but doesn't dispatch the normal "server ready" report —
     * used by the replace-server flow (ReplaceServerConversation and its
     * jobs), which polls/retries/finishes with its own logic instead.
     *
     * @return array{0: int|string|null, 1: int|string|null, 2: string} [action id, new server id, root password]
     */
    public function createSilently(Panel $panel, string $hostname, string $region, string $size, string $image): array
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
            // DigitalOcean sets the root password via this cloud-init script
            // (it has no direct "password" field); Linode has a native
            // root_pass field instead and uses root_password directly. Each
            // client picks whichever key(s) it needs from this shared array.
            'user_data' => $userData,
            'root_password' => $password,
        ]);

        $actionId = $result['links']['actions'][0]['id'] ?? null;
        $serverId = $result['droplet']['id'] ?? null;

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

        return [$actionId, $serverId, $password];
    }
}
