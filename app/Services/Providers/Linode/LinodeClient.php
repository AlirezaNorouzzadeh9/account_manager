<?php

namespace App\Services\Providers\Linode;

use App\Services\Providers\ProviderClient;
use App\Services\Providers\ProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Linode's API (https://api.linode.com/v4) is shaped very differently from
 * DigitalOcean's — every method here translates Linode's field names/values
 * into the same shape DigitalOceanClient returns, since the bot's
 * conversations/menus are written against DO's shape and aren't
 * provider-aware. See ProviderClient for the shared contract.
 *
 * Two structural differences worth knowing before touching this file:
 *
 * - Linode has no DO-style "action" you poll by id — creating/booting/
 *   resizing a Linode just changes its own `status` field over time. To
 *   keep PollProviderActionJob working unmodified, every action method here
 *   returns a fake action `{id: <linode id>}`, and getAction() answers by
 *   re-fetching that Linode and translating its current `status` into
 *   DO's {status, resource_type, resource_id} shape.
 *
 * - Linode's rebuild requires a NEW root password in the same request (no
 *   other way to keep access) — rebuild() generates one and returns it as
 *   `root_password` in the result so ServerListMenu can persist it instead
 *   of leaving the stored password stale.
 */
class LinodeClient implements ProviderClient
{
    protected const BASE_URL = 'https://api.linode.com/v4';

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
            $message = collect($response->json('errors'))
                ->pluck('reason')
                ->filter()
                ->implode('، ');

            throw new ProviderException($message !== '' ? $message : 'خطای نامشخص از سمت Linode', $response->status());
        }

        return $response->json() ?? [];
    }

    public function account(): array
    {
        return $this->handle($this->http()->get('/account'));
    }

    /**
     * Best-effort flag for a Linode region id — Linode's older region ids
     * (e.g. "eu-west") don't encode an ISO country code the way the newer
     * ones (e.g. "gb-lon", "fr-par") do, so this is a hand-picked table
     * rather than a derived one, same trade-off DigitalOceanClient makes.
     */
    protected const REGION_FLAGS = [
        'us-east' => '🇺🇸', 'us-west' => '🇺🇸', 'us-central' => '🇺🇸', 'us-southeast' => '🇺🇸',
        'us-iad' => '🇺🇸', 'us-ord' => '🇺🇸', 'us-sea' => '🇺🇸', 'us-mia' => '🇺🇸', 'us-lax' => '🇺🇸',
        'ca-central' => '🇨🇦',
        'eu-west' => '🇬🇧', 'gb-lon' => '🇬🇧',
        'eu-central' => '🇩🇪', 'de-fra-2' => '🇩🇪',
        'fr-par' => '🇫🇷',
        'nl-ams' => '🇳🇱',
        'se-sto' => '🇸🇪',
        'it-mil' => '🇮🇹',
        'es-mad' => '🇪🇸',
        'ap-south' => '🇸🇬', 'sg-sin-2' => '🇸🇬',
        'ap-northeast' => '🇯🇵', 'jp-osa' => '🇯🇵', 'jp-tyo-3' => '🇯🇵',
        'ap-southeast' => '🇦🇺', 'au-mel' => '🇦🇺',
        'in-maa' => '🇮🇳',
        'id-cgk' => '🇮🇩',
        'br-gru' => '🇧🇷',
    ];

    public function regionFlag(string $slug): string
    {
        return self::REGION_FLAGS[$slug] ?? '🌐';
    }

    public function regions(): array
    {
        $regions = $this->handle($this->http()->get('/regions'))['data'] ?? [];

        $regions = array_map(function (array $region) {
            $flag = $this->regionFlag($region['id']);

            return [
                'slug' => $region['id'],
                'name' => $region['label'],
                'flag' => $flag,
                'label' => trim("{$flag} {$region['label']}"),
            ];
        }, $regions);

        usort($regions, fn (array $a, array $b) => $a['label'] <=> $b['label']);

        return $regions;
    }

    public function sizes(?string $region = null): array
    {
        // $region is intentionally unused: unlike DO, Linode types aren't
        // restricted per-region (only priced differently in a few, via
        // region_prices) — every type is offered everywhere that matters here.
        $types = $this->handle($this->http()->get('/linode/types'))['data'] ?? [];

        // GPU/dedicated-premium classes have their own confusing pricing
        // tiers not worth surfacing in this bot's plan picker.
        $types = array_values(array_filter($types, fn (array $t) => ($t['class'] ?? '') !== 'gpu'));

        return array_map(fn (array $t) => [
            'slug' => $t['id'],
            'vcpus' => $t['vcpus'],
            'memory' => $t['memory'],
            'disk' => (int) round(($t['disk'] ?? 0) / 1024),
            'price_monthly' => $t['price']['monthly'] ?? 0,
        ], $types);
    }

    public function images(string $type = 'distribution'): array
    {
        $images = $this->handle($this->http()->get('/images'))['data'] ?? [];

        // Official public images ("linode/...") match DO's "distribution"
        // type; anything else is a private/custom snapshot.
        $images = array_values(array_filter(
            $images,
            fn (array $img) => str_starts_with($img['id'] ?? '', 'linode/') === ($type === 'distribution')
        ));

        // Linode's "distribution" images also include LKE's per-Kubernetes-
        // version cluster node images ("Kubernetes 1.30.3 on Debian ...") —
        // not a real standalone OS choice, just clutter for this bot's
        // plain-server rebuild/create pickers.
        $images = array_values(array_filter(
            $images,
            fn (array $img) => ! str_starts_with($img['label'] ?? '', 'Kubernetes')
        ));

        return array_map(fn (array $img) => [
            'slug' => $img['id'],
            'label' => $img['label'] ?? $img['id'],
        ], $images);
    }

    protected function sanitizeLabel(string $name): string
    {
        // Linode labels: 3-64 chars, must start with a letter, only
        // letters/numbers/[_-.]. Our hostname validation upstream is a
        // strict subset of this already, except the 3-char minimum.
        $label = substr($name, 0, 64);

        return strlen($label) >= 3 ? $label : str_pad($label, 3, '0');
    }

    /**
     * @return array{distribution: string, name: string}
     */
    protected function splitImageId(?string $imageId): array
    {
        if (! $imageId) {
            return ['distribution' => '', 'name' => '-'];
        }

        [$vendor, $rest] = array_pad(explode('/', $imageId, 2), 2, '');

        return ['distribution' => ucfirst($vendor), 'name' => $rest !== '' ? $rest : $imageId];
    }

    /**
     * Reshapes a Linode instance object into DO's "droplet" shape.
     */
    protected function normalizeInstance(array $instance): array
    {
        return [
            'id' => $instance['id'],
            'name' => $instance['label'] ?? '',
            'status' => match ($instance['status'] ?? '') {
                'running' => 'active',
                'offline' => 'off',
                default => $instance['status'] ?? 'new',
            },
            'networks' => [
                'v4' => array_map(
                    fn (string $ip) => ['ip_address' => $ip, 'type' => 'public'],
                    $instance['ipv4'] ?? []
                ),
            ],
            'region' => ['slug' => $instance['region'] ?? '', 'name' => $instance['region'] ?? '-'],
            'size_slug' => $instance['type'] ?? '',
            'image' => $this->splitImageId($instance['image'] ?? null),
        ];
    }

    public function createServer(array $data): array
    {
        $payload = [
            'label' => $this->sanitizeLabel($data['name'] ?? ''),
            'region' => $data['region'],
            'type' => $data['size'],
            'image' => $data['image'],
            'booted' => true,
        ];

        if (! empty($data['root_password'])) {
            $payload['root_pass'] = $data['root_password'];
        }

        try {
            $instance = $this->handle($this->http()->post('/linode/instances', $payload));
        } catch (ProviderException $e) {
            // Unlike DigitalOcean, Linode enforces unique labels account-wide
            // — this hits whenever a server is replaced/recreated under the
            // same hostname while the original (same label) is still up.
            // Retry once with a short random suffix instead of failing the
            // whole create/replace flow over a naming collision.
            if (! str_contains($e->getMessage(), 'unique')) {
                throw $e;
            }

            $payload['label'] = substr($payload['label'], 0, 58).'-'.Str::random(4);
            $instance = $this->handle($this->http()->post('/linode/instances', $payload));
        }

        return [
            'droplet' => $this->normalizeInstance($instance),
            'links' => ['actions' => [['id' => $instance['id']]]],
        ];
    }

    /**
     * Linode requires page_size to be 25-500 — below the 8-per-screen this
     * bot's UI actually wants — so this fetches every instance at a valid
     * page_size and paginates client-side to keep the same page/perPage
     * contract the other providers use.
     */
    protected function fetchAllInstances(): array
    {
        $items = [];
        $page = 1;

        do {
            $body = $this->handle($this->http()->get('/linode/instances', [
                'page' => $page,
                'page_size' => 100,
            ]));
            $items = array_merge($items, $body['data'] ?? []);
            $hasMore = $page < ($body['pages'] ?? 1);
            $page++;
        } while ($hasMore);

        return $items;
    }

    public function listServers(int $page = 1, int $perPage = 20): array
    {
        $all = $this->fetchAllInstances();
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_map(fn (array $i) => $this->normalizeInstance($i), array_slice($all, $offset, $perPage)),
            'has_more' => count($all) > $offset + $perPage,
        ];
    }

    public function getServer(int|string $id): array
    {
        $instance = $this->handle($this->http()->get("/linode/instances/{$id}"));
        $normalized = $this->normalizeInstance($instance);

        try {
            $type = $this->handle($this->http()->get('/linode/types/'.($instance['type'] ?? '')));
            $normalized['size'] = [
                'slug' => $type['id'],
                'vcpus' => $type['vcpus'],
                'memory' => $type['memory'],
                'disk' => (int) round(($type['disk'] ?? 0) / 1024),
                'price_monthly' => $type['price']['monthly'] ?? 0,
            ];
        } catch (Throwable) {
            // best-effort enrichment only — the plain size_slug fallback still works
        }

        return $normalized;
    }

    public function deleteServer(int|string $id): void
    {
        $this->handle($this->http()->delete("/linode/instances/{$id}"));
    }

    public function powerOn(int|string $id): array
    {
        $this->handle($this->http()->post("/linode/instances/{$id}/boot"));

        return ['id' => $id];
    }

    public function powerOff(int|string $id): array
    {
        $this->handle($this->http()->post("/linode/instances/{$id}/shutdown"));

        return ['id' => $id];
    }

    public function reboot(int|string $id): array
    {
        $this->handle($this->http()->post("/linode/instances/{$id}/reboot"));

        return ['id' => $id];
    }

    public function resize(int|string $id, string $size, bool $resizeDisk): array
    {
        $this->handle($this->http()->post("/linode/instances/{$id}/resize", [
            'type' => $size,
            'allow_auto_disk_resize' => $resizeDisk,
        ]));

        return ['id' => $id];
    }

    public function rebuild(int|string $id, string $image): array
    {
        $newPassword = Str::password(20, symbols: false);

        $this->handle($this->http()->post("/linode/instances/{$id}/rebuild", [
            'image' => $image,
            'root_pass' => $newPassword,
        ]));

        return ['id' => $id, 'root_password' => $newPassword];
    }

    /**
     * There's no separate "action" resource for Linode — $actionId is
     * really just the Linode's own id (see the class docblock), so this
     * re-fetches it and translates its current status.
     */
    public function getAction(int|string $actionId): array
    {
        $instance = $this->handle($this->http()->get("/linode/instances/{$actionId}"));

        $transitional = in_array($instance['status'] ?? '', [
            'provisioning', 'booting', 'rebooting', 'shutting_down',
            'rebuilding', 'resizing', 'migrating', 'cloning', 'restoring', 'deleting',
        ], true);

        return [
            'status' => $transitional ? 'in-progress' : 'completed',
            'resource_type' => 'droplet',
            'resource_id' => $instance['id'],
        ];
    }

    /**
     * Linode's "additional IPs" concept only loosely maps to DO's reserved
     * IPs — every public IPv4 belongs to a specific Linode the moment it's
     * allocated (no "unassigned floating pool"), so this treats a Linode's
     * public IPs beyond its first (primary) one as its "reserved" IPs.
     */
    public function listReservedIps(int|string|null $dropletId = null): array
    {
        if ($dropletId === null) {
            $ips = $this->handle($this->http()->get('/networking/ips'))['data'] ?? [];
            $ips = array_values(array_filter($ips, fn (array $ip) => $ip['public'] ?? false));

            return array_map(fn (array $ip) => [
                'ip' => $ip['address'],
                'droplet' => ['id' => $ip['linode_id'] ?? null],
                'region' => ['slug' => $ip['region'] ?? ''],
            ], $ips);
        }

        $ips = $this->handle($this->http()->get("/linode/instances/{$dropletId}/ips"))['ipv4']['public'] ?? [];

        // The first public IP is the Linode's primary/automatic one; only
        // the rest count as "extra"/reserved.
        $extra = array_slice($ips, 1);

        return array_map(fn (array $ip) => [
            'ip' => $ip['address'],
            'droplet' => ['id' => $dropletId],
            'region' => ['slug' => $ip['region'] ?? ''],
        ], $extra);
    }

    public function allocateReservedIp(string $region, int|string|null $dropletId = null): array
    {
        if ($dropletId === null) {
            throw new ProviderException('Linode برای اختصاص آی‌پی جدید، از قبل نیاز به مشخص‌بودن سرور مقصد دارد.');
        }

        $ip = $this->handle($this->http()->post('/networking/ips', [
            'type' => 'ipv4',
            'public' => true,
            'linode_id' => $dropletId,
        ]));

        return ['reserved_ip' => ['ip' => $ip['address'] ?? null]];
    }

    /**
     * A no-op for Linode: allocateReservedIp() already assigns the IP to
     * the target Linode directly (Linode has no separate "assign an
     * already-allocated IP" step), so there's no further action to poll.
     */
    public function assignReservedIp(string $ip, int|string $dropletId): array
    {
        return [];
    }

    public function unassignReservedIp(string $ip): array
    {
        throw new ProviderException('در Linode، جداکردن آی‌پی بدون حذف آن ممکن نیست — از حذف آی‌پی رزرو استفاده کنید.');
    }

    public function releaseReservedIp(string $ip): void
    {
        $owner = $this->handle($this->http()->get("/networking/ips/{$ip}"))['linode_id'] ?? null;

        if ($owner === null) {
            throw new ProviderException("آی‌پی {$ip} به هیچ سروری متصل نیست یا پیدا نشد.");
        }

        $this->handle($this->http()->delete("/linode/instances/{$owner}/ips/{$ip}"));
    }
}
