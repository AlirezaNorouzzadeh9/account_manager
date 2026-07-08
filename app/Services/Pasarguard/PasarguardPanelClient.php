<?php

namespace App\Services\Pasarguard;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the PasarGuard PANEL's own admin API (a completely
 * different system from a node itself). The node id currently registered
 * for a profile is stored on WireguardProfile::core_id: reconnectNode() is
 * used when a domain-backed node's IP changes underneath it (the panel
 * doesn't notice a DNS change on its own), while createNode()/deleteNode()
 * are used by the "🔄 تغییر سرور" flow, which now registers each
 * replacement server as a brand-new node (by its own IP, not a shared
 * domain) rather than editing/reconnecting the old one — see
 * ReplaceServerFinishJob.
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

    /**
     * @return array<string, mixed>
     */
    public function getNode(int $nodeId): array
    {
        $token = $this->login();

        $response = $this->http()
            ->withToken($token)
            ->get(rtrim($this->baseUrl, '/')."/api/node/{$nodeId}");

        if ($response->failed()) {
            throw new RuntimeException("دریافت اطلاعات نود {$nodeId} از پنل PasarGuard ناموفق بود (HTTP {$response->status()}).");
        }

        return $response->json();
    }

    /**
     * @param array<string, mixed> $attributes Must satisfy the panel's NodeCreate
     *   schema: name, address, connection_type, server_ca, keep_alive,
     *   core_config_id, api_key (port/api_port default to 62050/62051).
     */
    public function createNode(array $attributes): int
    {
        $token = $this->login();

        $response = $this->http()
            ->withToken($token)
            ->post(rtrim($this->baseUrl, '/').'/api/node', $attributes);

        if ($response->failed()) {
            throw new RuntimeException("ساخت نود جدید در پنل PasarGuard ناموفق بود (HTTP {$response->status()}: {$response->body()}).");
        }

        $id = $response->json('id');

        if (! $id) {
            throw new RuntimeException('ساخت نود جدید در پنل PasarGuard جواب نامعتبر داد (id برنگشت).');
        }

        return (int) $id;
    }

    public function deleteNode(int $nodeId): void
    {
        $token = $this->login();

        $response = $this->http()
            ->withToken($token)
            ->delete(rtrim($this->baseUrl, '/')."/api/node/{$nodeId}");

        if ($response->failed()) {
            throw new RuntimeException("حذف نود {$nodeId} از پنل PasarGuard ناموفق بود (HTTP {$response->status()}).");
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
