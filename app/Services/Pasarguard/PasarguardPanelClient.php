<?php

namespace App\Services\Pasarguard;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the PasarGuard PANEL's own admin API (a completely
 * different system from a node itself) — used only to force a node to
 * reconnect right after its domain's IP changes underneath it, since the
 * panel resolves the domain once and doesn't notice a DNS change on its
 * own. The node id to reconnect is stored per WireGuard profile (see
 * WireguardProfile::core_id) since a profile maps 1:1 to one panel node.
 */
class PasarguardPanelClient
{
    public function __construct(
        protected string $baseUrl,
        protected string $username,
        protected string $password,
    ) {
    }

    protected function http()
    {
        return Http::acceptJson()->timeout(15);
    }

    public function reconnectNode(int $nodeId): void
    {
        $token = $this->login();

        $response = $this->http()
            ->withToken($token)
            ->post(rtrim($this->baseUrl, '/')."/api/node/{$nodeId}/reconnect");

        if ($response->failed()) {
            throw new RuntimeException("درخواست ریکانکت نود در پنل PasarGuard ناموفق بود (HTTP {$response->status()}).");
        }
    }

    protected function login(): string
    {
        $response = $this->http()
            ->asForm()
            ->post(rtrim($this->baseUrl, '/').'/api/admin/token', [
                'grant_type' => 'password',
                'username' => $this->username,
                'password' => $this->password,
            ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! $token) {
            throw new RuntimeException('ورود به پنل PasarGuard ناموفق بود (یوزرنیم/پسورد اشتباه است یا آدرس پنل در دسترس نیست).');
        }

        return $token;
    }
}
