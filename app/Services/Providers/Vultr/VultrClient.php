<?php

namespace App\Services\Providers\Vultr;

use App\Services\Providers\ProviderClient;
use App\Services\Providers\ProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Vultr's API (https://api.vultr.com/v2) is shaped differently from
 * DigitalOcean's in the same ways Linode's is — see LinodeClient for the
 * general translation approach. Vultr-specific quirks:
 *
 * - Like Linode, Vultr has no DO-style pollable "action" resource: creating/
 *   resizing/reinstalling just changes the instance's own `status` (active/
 *   pending/suspended/resizing) and `server_status` (none/locked/
 *   installingbooting/ok) fields over time. Every action method here returns
 *   a fake action `{id: <instance id>}`; getAction() re-fetches the instance
 *   and derives DO's {status, resource_type, resource_id} shape from those
 *   two fields.
 *
 * - Vultr never accepts a plaintext root password anywhere in its API — it
 *   always auto-generates one unless cloud-init `user_data` overrides it.
 *   createServer()/rebuild() both set the password via the same cloud-config
 *   `chpasswd` script DigitalOceanClient relies on, except Vultr requires
 *   user_data to be base64-encoded.
 *
 * - Reserved IPs behave like DO's (a region-scoped floating pool, allocated
 *   unassigned and attached separately), so those five methods mirror
 *   DigitalOceanClient rather than Linode's per-instance workarounds.
 *
 * - Plans/instances paginate by opaque cursor, not page number. listServers()
 *   fetches every instance and re-paginates client-side to keep the same
 *   page/perPage contract the other providers use — fine for the handful of
 *   servers this bot manages per panel.
 */
class VultrClient implements ProviderClient
{
    protected const BASE_URL = 'https://api.vultr.com/v2';

    public function __construct(protected string $apiToken)
    {
    }

    protected function http(): PendingRequest
    {
        return Http::withToken($this->apiToken)
            ->baseUrl(self::BASE_URL)
            ->acceptJson()
            ->timeout(30);
    }

    protected function handle(Response $response): array
    {
        if ($response->failed()) {
            $message = $response->json('error') ?? 'خطای نامشخص از سمت Vultr';
            throw new ProviderException($message, $response->status());
        }

        return $response->json() ?? [];
    }

    public function account(): array
    {
        return $this->handle($this->http()->get('/account'))['account'] ?? [];
    }

    /**
     * Best-effort flag for a Vultr region id — regionFlag() only gets the
     * slug (no API call), so this is a hand-picked table of Vultr's known
     * airport-style region codes rather than something derived from the
     * `country` field regions() gets to see.
     */
    protected const REGION_FLAGS = [
        'ams' => '🇳🇱', 'atl' => '🇺🇸', 'bom' => '🇮🇳', 'blr' => '🇮🇳', 'cdg' => '🇫🇷',
        'del' => '🇮🇳', 'dfw' => '🇺🇸', 'ewr' => '🇺🇸', 'fra' => '🇩🇪', 'hnl' => '🇺🇸',
        'icn' => '🇰🇷', 'itm' => '🇯🇵', 'jnb' => '🇿🇦', 'lax' => '🇺🇸', 'lhr' => '🇬🇧',
        'mad' => '🇪🇸', 'man' => '🇬🇧', 'mel' => '🇦🇺', 'mex' => '🇲🇽', 'mia' => '🇺🇸',
        'nrt' => '🇯🇵', 'ord' => '🇺🇸', 'osl' => '🇳🇴', 'sao' => '🇧🇷', 'sgp' => '🇸🇬',
        'sjc' => '🇺🇸', 'sto' => '🇸🇪', 'syd' => '🇦🇺', 'tlv' => '🇮🇱', 'waw' => '🇵🇱',
        'yto' => '🇨🇦', 'sea' => '🇺🇸',
    ];

    public function regionFlag(string $slug): string
    {
        return self::REGION_FLAGS[$slug] ?? '🌐';
    }

    public function regions(): array
    {
        $regions = $this->handle($this->http()->get('/regions', ['per_page' => 500]))['regions'] ?? [];

        $regions = array_map(function (array $region) {
            $flag = $this->regionFlag($region['id']);
            $name = trim(($region['city'] ?? $region['id']).', '.strtoupper($region['country'] ?? ''), ' ,');

            return [
                'slug' => $region['id'],
                'name' => $name,
                'flag' => $flag,
                'label' => trim("{$flag} {$name}"),
            ];
        }, $regions);

        usort($regions, fn (array $a, array $b) => $a['label'] <=> $b['label']);

        return $regions;
    }

    public function sizes(?string $region = null): array
    {
        $plans = $this->handle($this->http()->get('/plans', ['per_page' => 500]))['plans'] ?? [];

        // "vdc" (Dedicated Cloud) plans sit in a completely different price
        // tier and would clutter the picker — every other type (vc2, vhf,
        // voc, ...) is a normal general-purpose VPS plan.
        $plans = array_values(array_filter($plans, fn (array $p) => ($p['type'] ?? '') !== 'vdc'));

        if ($region !== null) {
            $plans = array_values(array_filter(
                $plans,
                fn (array $p) => in_array($region, $p['locations'] ?? [], true)
            ));
        }

        return array_map(fn (array $p) => [
            'slug' => $p['id'],
            'vcpus' => $p['vcpu_count'],
            'memory' => $p['ram'],
            'disk' => $p['disk'],
            'price_monthly' => $p['monthly_cost'],
        ], $plans);
    }

    public function images(string $type = 'distribution'): array
    {
        // Vultr has one flat OS catalog (no distro/private split like DO or
        // Linode) — application/snapshot/iso/backup/custom entries are
        // excluded since this bot only ever asks for 'distribution'.
        $os = $this->handle($this->http()->get('/os', ['per_page' => 500]))['os'] ?? [];

        $excluded = ['application', 'snapshot', 'iso', 'backup', 'custom'];
        $os = array_values(array_filter(
            $os,
            fn (array $o) => ! in_array(strtolower($o['family'] ?? ''), $excluded, true)
        ));

        return array_map(fn (array $o) => [
            'slug' => (string) $o['id'],
            'label' => $o['name'] ?? (string) $o['id'],
        ], $os);
    }

    protected function splitOsName(?string $os): array
    {
        return ['distribution' => $os ?: '-', 'name' => ''];
    }

    protected function normalizeStatus(array $instance): string
    {
        if (($instance['status'] ?? '') === 'pending') {
            return 'new';
        }

        return match ($instance['power_status'] ?? '') {
            'running' => 'active',
            'stopped' => 'off',
            default => $instance['status'] ?? 'new',
        };
    }

    /**
     * Reshapes a Vultr instance object into DO's "droplet" shape.
     */
    protected function normalizeInstance(array $instance): array
    {
        $mainIp = $instance['main_ip'] ?? null;

        return [
            'id' => $instance['id'] ?? null,
            'name' => $instance['label'] ?: ($instance['hostname'] ?? ''),
            'status' => $this->normalizeStatus($instance),
            'networks' => [
                'v4' => $mainIp && $mainIp !== '0.0.0.0'
                    ? [['ip_address' => $mainIp, 'type' => 'public']]
                    : [],
            ],
            'region' => ['slug' => $instance['region'] ?? '', 'name' => $instance['region'] ?? '-'],
            'size_slug' => $instance['plan'] ?? '',
            'image' => $this->splitOsName($instance['os'] ?? null),
        ];
    }

    protected function cloudInitPasswordScript(string $password): string
    {
        return "#cloud-config\nchpasswd:\n  expire: false\n  list: |\n    root:{$password}\nssh_pwauth: true\n";
    }

    public function createServer(array $data): array
    {
        $payload = [
            'region' => $data['region'],
            'plan' => $data['size'],
            'os_id' => (int) $data['image'],
            'label' => substr($data['name'] ?? '', 0, 255),
            'hostname' => $data['name'] ?? null,
            'backups' => 'disabled',
            'enable_ipv6' => true,
            'activation_email' => false,
        ];

        // Vultr never accepts a plaintext password field — same cloud-init
        // trick DigitalOceanClient relies on, except Vultr requires user_data
        // to be base64-encoded.
        if (! empty($data['root_password'])) {
            $payload['user_data'] = base64_encode($this->cloudInitPasswordScript($data['root_password']));
        } elseif (! empty($data['user_data'])) {
            $payload['user_data'] = base64_encode($data['user_data']);
        }

        $instance = $this->handle($this->http()->post('/instances', $payload))['instance'] ?? [];

        return [
            'droplet' => $this->normalizeInstance($instance),
            'links' => ['actions' => [['id' => $instance['id'] ?? null]]],
        ];
    }

    protected function fetchAllInstances(): array
    {
        $items = [];
        $cursor = null;

        do {
            $query = ['per_page' => 500];
            if ($cursor) {
                $query['cursor'] = $cursor;
            }

            $body = $this->handle($this->http()->get('/instances', $query));
            $items = array_merge($items, $body['instances'] ?? []);
            $cursor = $body['meta']['links']['next'] ?? null;
        } while (! empty($cursor));

        return $items;
    }

    public function listServers(int $page = 1, int $perPage = 20): array
    {
        // Vultr paginates instances by opaque cursor, not page number, so
        // this fetches every instance (this bot manages at most a handful
        // per panel) and paginates client-side to keep the same
        // page/perPage contract the other providers use.
        $all = $this->fetchAllInstances();
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_map(fn (array $i) => $this->normalizeInstance($i), array_slice($all, $offset, $perPage)),
            'has_more' => count($all) > $offset + $perPage,
        ];
    }

    public function getServer(int|string $id): array
    {
        $instance = $this->handle($this->http()->get("/instances/{$id}"))['instance'] ?? [];
        $normalized = $this->normalizeInstance($instance);

        try {
            // No get-single-plan endpoint exists — enrichment needs the full list.
            $plans = $this->handle($this->http()->get('/plans', ['per_page' => 500]))['plans'] ?? [];
            $plan = collect($plans)->firstWhere('id', $instance['plan'] ?? null);

            if ($plan) {
                $normalized['size'] = [
                    'slug' => $plan['id'],
                    'vcpus' => $plan['vcpu_count'],
                    'memory' => $plan['ram'],
                    'disk' => $plan['disk'],
                    'price_monthly' => $plan['monthly_cost'],
                ];
            }
        } catch (Throwable) {
            // best-effort enrichment only — the plain size_slug fallback still works
        }

        return $normalized;
    }

    public function deleteServer(int|string $id): void
    {
        $this->handle($this->http()->delete("/instances/{$id}"));
    }

    public function powerOn(int|string $id): array
    {
        $this->handle($this->http()->post("/instances/{$id}/start"));

        return ['id' => $id];
    }

    public function powerOff(int|string $id): array
    {
        $this->handle($this->http()->post("/instances/{$id}/halt"));

        return ['id' => $id];
    }

    public function reboot(int|string $id): array
    {
        $this->handle($this->http()->post("/instances/{$id}/reboot"));

        return ['id' => $id];
    }

    public function resize(int|string $id, string $size, bool $resizeDisk): array
    {
        // $resizeDisk is intentionally unused: Vultr has no separate "grow
        // the disk too" toggle — a plan change resizes everything the new
        // plan specifies.
        $this->handle($this->http()->patch("/instances/{$id}", ['plan' => $size]));

        return ['id' => $id];
    }

    public function rebuild(int|string $id, string $image): array
    {
        $newPassword = Str::password(20, symbols: false);

        $this->handle($this->http()->patch("/instances/{$id}", [
            'os_id' => (int) $image,
            'user_data' => base64_encode($this->cloudInitPasswordScript($newPassword)),
        ]));

        return ['id' => $id, 'root_password' => $newPassword];
    }

    /**
     * There's no separate "action" resource for Vultr — $actionId is really
     * just the instance's own id (see the class docblock), so this
     * re-fetches it and derives a transitional/completed verdict from
     * `status` and `server_status`.
     */
    public function getAction(int|string $actionId): array
    {
        $instance = $this->handle($this->http()->get("/instances/{$actionId}"))['instance'] ?? [];

        $transitional = in_array($instance['status'] ?? '', ['pending', 'resizing'], true)
            || in_array($instance['server_status'] ?? '', ['locked', 'installingbooting'], true);

        return [
            'status' => $transitional ? 'in-progress' : 'completed',
            'resource_type' => 'droplet',
            'resource_id' => $instance['id'] ?? $actionId,
        ];
    }

    public function listReservedIps(int|string|null $dropletId = null): array
    {
        $ips = $this->handle($this->http()->get('/reserved-ips', ['per_page' => 500]))['reserved_ips'] ?? [];

        if ($dropletId !== null) {
            $ips = array_values(array_filter(
                $ips,
                fn (array $ip) => ($ip['instance_id'] ?? null) == $dropletId
            ));
        }

        return array_map(fn (array $ip) => [
            'ip' => $ip['subnet'] ?? '',
            'droplet' => ['id' => $ip['instance_id'] ?? null],
            'region' => ['slug' => $ip['region'] ?? ''],
        ], $ips);
    }

    public function allocateReservedIp(string $region, int|string|null $dropletId = null): array
    {
        $reserved = $this->handle($this->http()->post('/reserved-ips', [
            'region' => $region,
            'ip_type' => 'v4',
        ]))['reserved_ip'] ?? [];

        return ['reserved_ip' => ['ip' => $reserved['subnet'] ?? null]];
    }

    public function assignReservedIp(string $ip, int|string $dropletId): array
    {
        $this->handle($this->http()->post("/reserved-ips/{$ip}/attach", ['instance_id' => $dropletId]));

        // Attaching completes synchronously — Vultr has no action to poll.
        return [];
    }

    public function unassignReservedIp(string $ip): array
    {
        $this->handle($this->http()->post("/reserved-ips/{$ip}/detach"));

        return [];
    }

    public function releaseReservedIp(string $ip): void
    {
        $this->handle($this->http()->delete("/reserved-ips/{$ip}"));
    }
}
