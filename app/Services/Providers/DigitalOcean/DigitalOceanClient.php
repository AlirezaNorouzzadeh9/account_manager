<?php

namespace App\Services\Providers\DigitalOcean;

use App\Services\Providers\ProviderClient;
use App\Services\Providers\ProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DigitalOceanClient implements ProviderClient
{
    protected const BASE_URL = 'https://api.digitalocean.com/v2';

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
            $message = $response->json('message') ?? 'خطای نامشخص از سمت DigitalOcean';
            throw new ProviderException($message, $response->status());
        }

        return $response->json() ?? [];
    }

    public function account(): array
    {
        return $this->handle($this->http()->get('/account'))['account'] ?? [];
    }

    /**
     * Maps a region slug prefix (e.g. "nyc" from "nyc1") to its country's
     * display name, flag emoji, and sort rank. Unknown/future region
     * prefixes fall back to a neutral globe icon instead of being hidden.
     */
    protected const REGION_COUNTRIES = [
        'nyc' => ['United States', '🇺🇸', 1],
        'sfo' => ['United States', '🇺🇸', 1],
        'atl' => ['United States', '🇺🇸', 1],
        'ric' => ['United States', '🇺🇸', 1],
        'tor' => ['Canada', '🇨🇦', 2],
        'lon' => ['United Kingdom', '🇬🇧', 3],
        'ams' => ['Netherlands', '🇳🇱', 4],
        'fra' => ['Germany', '🇩🇪', 5],
        'sgp' => ['Singapore', '🇸🇬', 6],
        'blr' => ['India', '🇮🇳', 7],
        'syd' => ['Australia', '🇦🇺', 8],
    ];

    /**
     * Flag + country for a region slug, without any API call. Used to label
     * a single already-known region (e.g. in a server detail view).
     */
    public function regionFlag(string $slug): string
    {
        $prefix = substr($slug, 0, 3);

        return (self::REGION_COUNTRIES[$prefix] ?? [null, '🌐'])[1];
    }

    public function regions(): array
    {
        $regions = $this->handle($this->http()->get('/regions', ['per_page' => 200]))['regions'] ?? [];
        $regions = array_values(array_filter($regions, fn (array $region) => $region['available'] ?? false));

        $regions = array_map(function (array $region) {
            $prefix = substr($region['slug'], 0, 3);
            [$country, $flag, $order] = self::REGION_COUNTRIES[$prefix] ?? [null, '🌐', 99];

            $region['country'] = $country;
            $region['flag'] = $flag;
            $region['label'] = trim("{$flag} {$region['name']}");
            $region['sort_order'] = $order;

            return $region;
        }, $regions);

        usort($regions, fn (array $a, array $b) => $a['sort_order'] <=> $b['sort_order'] ?: $a['slug'] <=> $b['slug']);

        return $regions;
    }

    public function sizes(?string $region = null): array
    {
        $sizes = $this->handle($this->http()->get('/sizes', ['per_page' => 200]))['sizes'] ?? [];

        $sizes = array_values(array_filter($sizes, fn (array $size) => $size['available'] ?? false));

        if ($region !== null) {
            $sizes = array_values(array_filter(
                $sizes,
                fn (array $size) => in_array($region, $size['regions'] ?? [], true)
            ));
        }

        return $sizes;
    }

    public function images(string $type = 'distribution'): array
    {
        $images = $this->handle($this->http()->get('/images', [
            'type' => $type,
            'per_page' => 200,
        ]))['images'] ?? [];

        // GPU/AI-ML base images are returned under type=distribution too, but
        // this bot only deals with regular (non-GPU) droplets.
        $images = array_values(array_filter(
            $images,
            fn (array $image) => ! str_starts_with($image['slug'] ?? '', 'gpu-')
        ));

        return array_map(function (array $image) {
            $name = trim(preg_replace('/\s*x(64|32)\s*$/i', '', $image['name']));
            $distribution = $image['distribution'] ?? '';

            $image['label'] = str_starts_with($name, $distribution)
                ? $name
                : trim("{$distribution} {$name}");

            return $image;
        }, $images);
    }

    public function createServer(array $data): array
    {
        // Whitelisted so a field only some OTHER provider's client needs
        // (e.g. Linode's root_password, passed by ServerProvisioningService
        // through the same generic $data array) can never leak into DO's
        // request body.
        $payload = array_intersect_key($data, array_flip([
            'name', 'region', 'size', 'image', 'monitoring', 'ipv6', 'user_data', 'ssh_keys', 'backups', 'tags', 'vpc_uuid',
        ]));

        return $this->handle($this->http()->post('/droplets', $payload));
    }

    public function listServers(int $page = 1, int $perPage = 20): array
    {
        $body = $this->handle($this->http()->get('/droplets', [
            'page' => $page,
            'per_page' => $perPage,
        ]));

        return [
            'items' => $body['droplets'] ?? [],
            'has_more' => isset($body['links']['pages']['next']),
        ];
    }

    public function getServer(int|string $id): array
    {
        return $this->handle($this->http()->get("/droplets/{$id}"))['droplet'] ?? [];
    }

    public function deleteServer(int|string $id): void
    {
        $this->handle($this->http()->delete("/droplets/{$id}"));
    }

    protected function action(int|string $id, array $payload): array
    {
        return $this->handle($this->http()->post("/droplets/{$id}/actions", $payload))['action'] ?? [];
    }

    public function powerOn(int|string $id): array
    {
        return $this->action($id, ['type' => 'power_on']);
    }

    public function powerOff(int|string $id): array
    {
        return $this->action($id, ['type' => 'shutdown']);
    }

    public function reboot(int|string $id): array
    {
        return $this->action($id, ['type' => 'reboot']);
    }

    public function resize(int|string $id, string $size, bool $resizeDisk): array
    {
        return $this->action($id, ['type' => 'resize', 'size' => $size, 'disk' => $resizeDisk]);
    }

    public function rebuild(int|string $id, string $image): array
    {
        return $this->action($id, ['type' => 'rebuild', 'image' => $image]);
    }

    public function getAction(int|string $actionId): array
    {
        return $this->handle($this->http()->get("/actions/{$actionId}"))['action'] ?? [];
    }

    public function listReservedIps(int|string|null $dropletId = null): array
    {
        $ips = $this->handle($this->http()->get('/reserved_ips', ['per_page' => 200]))['reserved_ips'] ?? [];

        if ($dropletId !== null) {
            $ips = array_values(array_filter(
                $ips,
                fn (array $ip) => ($ip['droplet']['id'] ?? null) == $dropletId
            ));
        }

        return $ips;
    }

    public function allocateReservedIp(string $region, int|string|null $dropletId = null): array
    {
        return $this->handle($this->http()->post('/reserved_ips', ['region' => $region]));
    }

    public function assignReservedIp(string $ip, int|string $dropletId): array
    {
        return $this->handle($this->http()->post("/reserved_ips/{$ip}/actions", [
            'type' => 'assign',
            'droplet_id' => $dropletId,
        ]))['action'] ?? [];
    }

    public function unassignReservedIp(string $ip): array
    {
        return $this->handle($this->http()->post("/reserved_ips/{$ip}/actions", [
            'type' => 'unassign',
        ]))['action'] ?? [];
    }

    public function releaseReservedIp(string $ip): void
    {
        $this->handle($this->http()->delete("/reserved_ips/{$ip}"));
    }
}
