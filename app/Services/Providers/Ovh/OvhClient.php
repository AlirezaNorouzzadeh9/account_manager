<?php

namespace App\Services\Providers\Ovh;

use App\Services\Providers\ProviderClient;
use App\Services\Providers\ProviderException;
use App\Telegram\Support\FiltersUbuntuImages;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OVHcloud's API (https://api.ovhcloud.com) is shaped very differently from
 * every other provider here in two structural ways:
 *
 * - Auth is a signed-request scheme (Application Key/Secret + a per-account
 *   Consumer Key), not a bearer token. Every request needs X-Ovh-Application/
 *   -Consumer/-Timestamp/-Signature headers, where the signature is
 *   `$1$` + sha1(AppSecret+"+"+ConsumerKey+"+"+METHOD+"+"+URL+"+"+BODY+"+"+Timestamp).
 *   The Consumer Key itself has to be generated once and approved by the
 *   account owner in a browser (POST /auth/credential returns a
 *   validationUrl) — that step happens OUTSIDE this bot, the admin pastes in
 *   the already-validated AK/AS/CK/serviceName (Cloud Project id) instead.
 *
 * - This targets OVH's "Public Cloud" simple instance API
 *   (/cloud/project/{serviceName}/instance), which — unlike the classic VPS
 *   line — supports single-call creation with an automatic public IP. It has
 *   NO root-password field at all: initial access is cloud-init only, so
 *   createServer() reuses the same cloud-init chpasswd script
 *   ServerProvisioningService already generates for Vultr.
 *
 * Every method here still translates into DigitalOcean's shape, same as
 * every other client — see ProviderClient for the shared contract.
 */
class OvhClient implements ProviderClient
{
    use FiltersUbuntuImages;

    protected const BASE_URL = 'https://eu.api.ovh.com/1.0';

    protected ?int $timeDelta = null;

    public function __construct(
        protected string $consumerKey,
        protected string $applicationKey,
        protected string $applicationSecret,
        protected string $serviceName,
    ) {
    }

    /**
     * OVH rejects a signature computed against a locally-drifted clock, so
     * every timestamp is offset by the difference between OVH's own clock
     * (GET /auth/time — a plain-text Unix timestamp, no auth needed) and
     * ours, cached for the life of this client instance.
     */
    protected function timeDelta(): int
    {
        if ($this->timeDelta === null) {
            try {
                $serverTime = (int) trim(Http::timeout(15)->get(self::BASE_URL.'/auth/time')->body());
                $this->timeDelta = $serverTime > 0 ? $serverTime - time() : 0;
            } catch (Throwable) {
                $this->timeDelta = 0;
            }
        }

        return $this->timeDelta;
    }

    /**
     * $body === null means a genuinely bodyless request (GET/DELETE, or a
     * POST action like start/stop with nothing to send) — OVH signs those
     * against an empty string, and sending "[]"/"{}" instead would make the
     * signature mismatch the body actually sent and fail as
     * INVALID_SIGNATURE, so withBody('', ...) is used instead of post()'s
     * default empty-array body.
     */
    protected function request(string $method, string $path, ?array $body = null): array
    {
        $url = self::BASE_URL.$path;
        $timestamp = time() + $this->timeDelta();
        $bodyJson = $body === null ? '' : json_encode($body);

        $signature = '$1$'.sha1("{$this->applicationSecret}+{$this->consumerKey}+{$method}+{$url}+{$bodyJson}+{$timestamp}");

        $request = Http::withHeaders([
            'X-Ovh-Application' => $this->applicationKey,
            'X-Ovh-Consumer' => $this->consumerKey,
            'X-Ovh-Timestamp' => (string) $timestamp,
            'X-Ovh-Signature' => $signature,
        ])->acceptJson()->timeout(30);

        $response = match (true) {
            $method === 'GET' => $request->get($url),
            $method === 'DELETE' => $request->withBody('', 'application/json')->delete($url),
            $body === null => $request->withBody('', 'application/json')->post($url),
            default => $request->post($url, $body),
        };

        return $this->handle($response);
    }

    protected function handle(Response $response): array
    {
        if ($response->failed()) {
            throw new ProviderException($response->json('message') ?? 'خطای نامشخص از سمت OVH', $response->status());
        }

        return $response->json() ?? [];
    }

    public function account(): array
    {
        $project = $this->request('GET', "/cloud/project/{$this->serviceName}");

        return [
            'email' => $project['description'] ?? $this->serviceName,
            'uuid' => $this->serviceName,
        ];
    }

    /**
     * Best-effort flag derived from the region CODE's prefix — OVH's region
     * list is just bare codes (e.g. "GRA11"), no country/display name, same
     * hardcoded-table trade-off DigitalOceanClient/LinodeClient make.
     */
    protected const REGION_FLAG_PREFIXES = [
        'GRA' => '🇫🇷', 'SBG' => '🇫🇷', 'PAR' => '🇫🇷', 'RBX' => '🇫🇷',
        'BHS' => '🇨🇦',
        'DE' => '🇩🇪',
        'UK' => '🇬🇧', 'LON' => '🇬🇧',
        'WAW' => '🇵🇱',
        'SGP' => '🇸🇬',
        'SYD' => '🇦🇺',
        'US' => '🇺🇸', 'VIN' => '🇺🇸',
    ];

    public function regionFlag(string $slug): string
    {
        foreach (self::REGION_FLAG_PREFIXES as $prefix => $flag) {
            if (str_starts_with(strtoupper($slug), $prefix)) {
                return $flag;
            }
        }

        return '🌐';
    }

    public function regions(): array
    {
        $codes = $this->request('GET', "/cloud/project/{$this->serviceName}/region");

        $regions = array_map(function (string $code) {
            $flag = $this->regionFlag($code);

            return ['slug' => $code, 'name' => $code, 'flag' => $flag, 'label' => trim("{$flag} {$code}")];
        }, $codes);

        usort($regions, fn (array $a, array $b) => $a['label'] <=> $b['label']);

        return $regions;
    }

    /**
     * OVH flavors carry no inline price — only a real cost estimate needs a
     * second, unauthenticated call to the public order catalog, joined on
     * the flavor's monthly planCode. Prices there are in EUR-denominated
     * "ucents" (1 EUR = 100,000,000 ucents) since this targets the EU API.
     *
     * @return array<string, float> planCode => price per month
     */
    protected function planPrices(): array
    {
        try {
            $response = Http::acceptJson()->timeout(15)
                ->get('https://eu.api.ovh.com/1.0/order/catalog/public/cloud', ['ovhSubsidiary' => 'FR']);

            if ($response->failed()) {
                return [];
            }

            $prices = [];

            foreach ($response->json('addons') ?? [] as $addon) {
                $planCode = $addon['planCode'] ?? null;
                $price = $addon['pricings'][0]['price'] ?? null;

                if ($planCode !== null && $price !== null) {
                    $prices[$planCode] = round($price / 100000000, 2);
                }
            }

            return $prices;
        } catch (Throwable) {
            return [];
        }
    }

    public function sizes(?string $region = null): array
    {
        $path = "/cloud/project/{$this->serviceName}/flavor".($region ? '?region='.urlencode($region) : '');
        $flavors = $this->request('GET', $path);

        $flavors = array_values(array_filter($flavors, fn (array $f) => ($f['available'] ?? false) === true));

        $prices = $this->planPrices();

        return array_map(function (array $f) use ($prices) {
            $planCode = $f['planCodes']['monthly'] ?? null;

            return [
                'slug' => $f['id'],
                'vcpus' => $f['vcpus'] ?? 0,
                // OVH reports ram in GiB; every other client here (and
                // FormatsServerSize) expects MB.
                'memory' => (int) round(($f['ram'] ?? 0) * 1024),
                'disk' => $f['disk'] ?? 0,
                'price_monthly' => $planCode !== null ? ($prices[$planCode] ?? 0) : 0,
            ];
        }, $flavors);
    }

    public function images(string $type = 'distribution'): array
    {
        if ($type !== 'distribution') {
            return [];
        }

        $images = $this->request('GET', "/cloud/project/{$this->serviceName}/image?osType=linux");

        return $this->onlyUbuntu(array_map(fn (array $img) => [
            'slug' => $img['id'],
            'label' => $img['name'] ?? $img['id'],
        ], $images));
    }

    protected function normalizeInstance(array $instance): array
    {
        $ipv4 = collect($instance['ipAddresses'] ?? [])
            ->first(fn (array $ip) => ($ip['version'] ?? null) === 4 && ($ip['type'] ?? null) === 'public');

        return [
            'id' => $instance['id'],
            'name' => $instance['name'] ?? '',
            'status' => match ($instance['status'] ?? '') {
                'ACTIVE' => 'active',
                'SHUTOFF' => 'off',
                default => strtolower($instance['status'] ?? 'new'),
            },
            'networks' => [
                'v4' => $ipv4 ? [['ip_address' => $ipv4['ip'], 'type' => 'public']] : [],
            ],
            'region' => ['slug' => $instance['region'] ?? '', 'name' => $instance['region'] ?? '-'],
            'size_slug' => $instance['flavorId'] ?? '',
            'image' => ['distribution' => 'OVH', 'name' => $instance['imageId'] ?? '-'],
        ];
    }

    public function createServer(array $data): array
    {
        $payload = [
            'name' => $data['name'] ?? '',
            'flavorId' => $data['size'],
            'imageId' => $data['image'],
            'region' => $data['region'],
            'monthlyBilling' => false,
        ];

        // OVH has no root-password field — access is SSH-key or cloud-init
        // only. Reuses whichever cloud-init script the caller already built
        // for DigitalOcean/Vultr (see ServerProvisioningService).
        if (! empty($data['user_data'])) {
            $payload['userData'] = $data['user_data'];
        }

        $instance = $this->request('POST', "/cloud/project/{$this->serviceName}/instance", $payload);

        return [
            'droplet' => $this->normalizeInstance($instance),
            'links' => ['actions' => [['id' => $instance['id']]]],
        ];
    }

    public function listServers(int $page = 1, int $perPage = 20): array
    {
        $all = $this->request('GET', "/cloud/project/{$this->serviceName}/instance");
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_map(fn (array $i) => $this->normalizeInstance($i), array_slice($all, $offset, $perPage)),
            'has_more' => count($all) > $offset + $perPage,
        ];
    }

    public function getServer(int|string $id): array
    {
        $instance = $this->request('GET', "/cloud/project/{$this->serviceName}/instance/{$id}");
        $normalized = $this->normalizeInstance($instance);

        try {
            $flavor = $this->request('GET', "/cloud/project/{$this->serviceName}/flavor/{$instance['flavorId']}");
            $prices = $this->planPrices();
            $planCode = $flavor['planCodes']['monthly'] ?? null;

            $normalized['size'] = [
                'slug' => $flavor['id'],
                'vcpus' => $flavor['vcpus'] ?? 0,
                'memory' => (int) round(($flavor['ram'] ?? 0) * 1024),
                'disk' => $flavor['disk'] ?? 0,
                'price_monthly' => $planCode !== null ? ($prices[$planCode] ?? 0) : 0,
            ];
        } catch (Throwable) {
            // best-effort enrichment only — the plain size_slug fallback still works
        }

        return $normalized;
    }

    public function deleteServer(int|string $id): void
    {
        $this->request('DELETE', "/cloud/project/{$this->serviceName}/instance/{$id}");
    }

    public function powerOn(int|string $id): array
    {
        $this->request('POST', "/cloud/project/{$this->serviceName}/instance/{$id}/start", null);

        return ['id' => $id];
    }

    public function powerOff(int|string $id): array
    {
        $this->request('POST', "/cloud/project/{$this->serviceName}/instance/{$id}/stop", null);

        return ['id' => $id];
    }

    public function reboot(int|string $id): array
    {
        $this->request('POST', "/cloud/project/{$this->serviceName}/instance/{$id}/reboot", ['type' => 'soft']);

        return ['id' => $id];
    }

    public function resize(int|string $id, string $size, bool $resizeDisk): array
    {
        // OVH has no separate "resize the disk too" toggle — $resizeDisk is
        // unused, kept only to satisfy the shared interface.
        $this->request('POST', "/cloud/project/{$this->serviceName}/instance/{$id}/resize", ['flavorId' => $size]);

        return ['id' => $id];
    }

    /**
     * OVH's reinstall endpoint only accepts {imageId} — there's no way to
     * set a new root password/cloud-init in the same call, so unlike every
     * other provider here, a rebuild would leave the admin with no
     * documented way to regain access. Matches the same "not supported yet"
     * decision made for Azure rather than shipping a half-working rebuild.
     */
    public function rebuild(int|string $id, string $image): array
    {
        throw new ProviderException('ریبیلد سیستم‌عامل برای OVH در حال حاضر پشتیبانی نمی‌شود (چون رمز روت جدید در همان درخواست قابل تنظیم نیست).');
    }

    /**
     * There's no separate "action" resource for OVH either — $actionId is
     * the instance's own id, and this re-fetches it and translates its
     * current status.
     */
    public function getAction(int|string $actionId): array
    {
        $instance = $this->request('GET', "/cloud/project/{$this->serviceName}/instance/{$actionId}");
        $status = $instance['status'] ?? '';

        $transitional = in_array($status, ['BUILD', 'RESIZE', 'REBOOT', 'RESCUE', 'SNAPSHOTTING'], true);

        return [
            'status' => $transitional ? 'in-progress' : ($status === 'ACTIVE' ? 'completed' : 'error'),
            'resource_type' => 'droplet',
            'resource_id' => $instance['id'],
        ];
    }

    /**
     * OVH's floating IPs are region-scoped, so listing them needs to first
     * resolve which region the target instance lives in — there's no
     * project-wide listing without knowing (or scanning every) region.
     */
    public function listReservedIps(int|string|null $dropletId = null): array
    {
        if ($dropletId === null) {
            throw new ProviderException('OVH برای لیست آی‌پی‌های رزرو، نیاز به مشخص‌بودن سرور مقصد دارد.');
        }

        $instance = $this->request('GET', "/cloud/project/{$this->serviceName}/instance/{$dropletId}");
        $region = $instance['region'] ?? '';

        $ips = $this->request('GET', "/cloud/project/{$this->serviceName}/region/{$region}/floatingip");

        return collect($ips)
            ->filter(fn (array $fip) => ($fip['associatedEntity']['id'] ?? null) == $dropletId)
            ->map(fn (array $fip) => ['ip' => $fip['ip'] ?? null, 'droplet' => ['id' => $dropletId], 'region' => ['slug' => $region]])
            ->values()
            ->all();
    }

    public function allocateReservedIp(string $region, int|string|null $dropletId = null): array
    {
        if ($dropletId === null) {
            throw new ProviderException('OVH فقط امکان ساخت آی‌پی رزرو مستقیم روی یک سرور مشخص را دارد.');
        }

        $fip = $this->request(
            'POST',
            "/cloud/project/{$this->serviceName}/region/{$region}/instance/{$dropletId}/floatingIp",
            null
        );

        return ['reserved_ip' => ['ip' => $fip['ip'] ?? null]];
    }

    /**
     * A no-op for OVH: allocateReservedIp() already creates AND attaches the
     * floating IP to the target instance in one call — there's no separate
     * "assign an already-allocated IP" step to poll.
     */
    public function assignReservedIp(string $ip, int|string $dropletId): array
    {
        return [];
    }

    public function unassignReservedIp(string $ip): array
    {
        throw new ProviderException('در OVH، جداکردن آی‌پی رزرو بدون حذف آن پشتیبانی نمی‌شود — از حذف آی‌پی رزرو استفاده کنید.');
    }

    /**
     * OVH's delete-floating-ip endpoint needs both the region AND the
     * floating IP's own id, neither of which this method's plain-IP-string
     * signature carries — not implemented rather than an expensive/unsafe
     * scan across every region to find it.
     */
    public function releaseReservedIp(string $ip): void
    {
        throw new ProviderException('حذف آی‌پی رزرو برای OVH در حال حاضر پشتیبانی نمی‌شود؛ از پنل OVH حذفش کنید.');
    }
}
