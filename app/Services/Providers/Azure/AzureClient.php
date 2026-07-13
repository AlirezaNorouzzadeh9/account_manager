<?php

namespace App\Services\Providers\Azure;

use App\Services\Providers\ProviderClient;
use App\Services\Providers\ProviderException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Azure's ARM API is structurally unlike DigitalOcean/Linode/Vultr in two
 * ways every method here has to work around:
 *
 * - Auth is OAuth2 client-credentials against Microsoft Entra ID (tenant_id
 *   + client_id + client_secret), not a single bearer token — token() mints
 *   and caches a short-lived access token before every request.
 *
 * - A VM is not a single resource: creating one needs a resource group, a
 *   per-region vnet/subnet/NSG (created once, reused by every server in
 *   that region), and a dedicated public IP + NIC per server, in that
 *   order, before the VM itself can be created. deleteServer() reverses
 *   this chain so those billable resources don't leak.
 *
 * Every method still translates into DigitalOcean's response shape, same as
 * every other client — the bot's conversations/menus aren't provider-aware.
 *
 * Azure also refuses "root" as an adminUsername at VM-creation time, so
 * createServer() provisions a throwaway "azureuser" account and relies on
 * the shared cloud-init user_data (ServerProvisioningService) — with an
 * appended directive forcing PermitRootLogin — to actually enable root SSH
 * access, keeping every downstream flow (rebuild-password, node install,
 * WireGuard deploy) working exactly like the other providers.
 *
 * "Rebuild" (wipe + reinstall OS, same IP) has no single-call equivalent
 * here (it would mean detaching/deleting the OS disk and attaching a fresh
 * one) and reserved/secondary IPs are likewise not implemented yet — both
 * throw a clear ProviderException instead of half-working.
 */
class AzureClient implements ProviderClient
{
    protected const BASE_URL = 'https://management.azure.com';

    protected const COMPUTE_API_VERSION = '2024-07-01';

    protected const NETWORK_API_VERSION = '2024-05-01';

    protected const RESOURCE_GROUP_API_VERSION = '2022-09-01';

    protected const SUBSCRIPTIONS_API_VERSION = '2022-12-01';

    protected const DISK_API_VERSION = '2024-03-02';

    protected ?string $accessToken = null;

    protected int $tokenExpiresAt = 0;

    public function __construct(
        protected string $clientSecret,
        protected string $tenantId,
        protected string $clientId,
        protected string $subscriptionId,
        protected string $resourceGroup,
    ) {
    }

    protected function token(): string
    {
        if ($this->accessToken !== null && time() < $this->tokenExpiresAt) {
            return $this->accessToken;
        }

        $response = Http::asForm()->timeout(30)->post(
            "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
            [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://management.azure.com/.default',
            ]
        );

        if ($response->failed()) {
            $message = $response->json('error_description') ?? $response->json('error') ?? 'خطای نامشخص در احراز هویت Azure';
            $message = is_string($message) ? Str::before($message, "\r\n") : 'خطای نامشخص در احراز هویت Azure';

            throw new ProviderException($message, $response->status());
        }

        $this->accessToken = $response->json('access_token');
        $this->tokenExpiresAt = time() + (int) ($response->json('expires_in') ?? 3600) - 60;

        return $this->accessToken;
    }

    protected function http()
    {
        return Http::withToken($this->token())->baseUrl(self::BASE_URL)->acceptJson()->timeout(30);
    }

    protected function url(string $path, string $apiVersion): string
    {
        $separator = str_contains($path, '?') ? '&' : '?';

        return "{$path}{$separator}api-version={$apiVersion}";
    }

    protected function handle(Response $response): array
    {
        if ($response->failed()) {
            throw new ProviderException($response->json('error.message') ?? 'خطای نامشخص از سمت Azure', $response->status());
        }

        return $response->json() ?? [];
    }

    public function account(): array
    {
        $sub = $this->handle($this->http()->get($this->url("/subscriptions/{$this->subscriptionId}", self::SUBSCRIPTIONS_API_VERSION)));

        return [
            'email' => $sub['displayName'] ?? $this->subscriptionId,
            'uuid' => $sub['subscriptionId'] ?? $this->subscriptionId,
        ];
    }

    /**
     * Best-effort flag table, same trade-off as DigitalOceanClient/LinodeClient
     * — Azure has ~60 regions, unmapped ones fall back to 🌐.
     */
    protected const REGION_FLAGS = [
        'eastus' => '🇺🇸', 'eastus2' => '🇺🇸', 'centralus' => '🇺🇸', 'westus' => '🇺🇸', 'westus2' => '🇺🇸', 'westus3' => '🇺🇸',
        'southcentralus' => '🇺🇸', 'northcentralus' => '🇺🇸', 'westcentralus' => '🇺🇸',
        'canadacentral' => '🇨🇦', 'canadaeast' => '🇨🇦',
        'brazilsouth' => '🇧🇷',
        'northeurope' => '🇮🇪', 'westeurope' => '🇳🇱',
        'uksouth' => '🇬🇧', 'ukwest' => '🇬🇧',
        'francecentral' => '🇫🇷', 'francesouth' => '🇫🇷',
        'germanywestcentral' => '🇩🇪', 'germanynorth' => '🇩🇪',
        'switzerlandnorth' => '🇨🇭', 'switzerlandwest' => '🇨🇭',
        'norwayeast' => '🇳🇴', 'norwaywest' => '🇳🇴',
        'swedencentral' => '🇸🇪',
        'polandcentral' => '🇵🇱',
        'italynorth' => '🇮🇹',
        'spaincentral' => '🇪🇸',
        'uaenorth' => '🇦🇪', 'uaecentral' => '🇦🇪',
        'southafricanorth' => '🇿🇦',
        'centralindia' => '🇮🇳', 'southindia' => '🇮🇳', 'westindia' => '🇮🇳',
        'eastasia' => '🇭🇰', 'southeastasia' => '🇸🇬',
        'japaneast' => '🇯🇵', 'japanwest' => '🇯🇵',
        'koreacentral' => '🇰🇷', 'koreasouth' => '🇰🇷',
        'australiaeast' => '🇦🇺', 'australiasoutheast' => '🇦🇺', 'australiacentral' => '🇦🇺',
        'qatarcentral' => '🇶🇦',
        'israelcentral' => '🇮🇱',
    ];

    public function regionFlag(string $slug): string
    {
        return self::REGION_FLAGS[$slug] ?? '🌐';
    }

    public function regions(): array
    {
        $locations = $this->handle($this->http()->get($this->url(
            "/subscriptions/{$this->subscriptionId}/locations",
            self::SUBSCRIPTIONS_API_VERSION
        )))['value'] ?? [];

        // Azure's /locations also lists "logical" entries (region pairs,
        // EUAP canaries, ...) with no regionType metadata — physical
        // datacenters only, same spirit as excluding Linode's LKE images.
        $locations = array_values(array_filter(
            $locations,
            fn (array $l) => ($l['metadata']['regionType'] ?? null) === 'Physical'
        ));

        $regions = array_map(function (array $l) {
            $flag = $this->regionFlag($l['name']);

            return [
                'slug' => $l['name'],
                'name' => $l['displayName'] ?? $l['name'],
                'flag' => $flag,
                'label' => trim("{$flag} ".($l['displayName'] ?? $l['name'])),
            ];
        }, $locations);

        usort($regions, fn (array $a, array $b) => $a['label'] <=> $b['label']);

        return $regions;
    }

    public function sizes(?string $region = null): array
    {
        if ($region === null) {
            throw new ProviderException('برای دریافت پلن‌ها ابتدا باید منطقه مشخص شود.');
        }

        $types = $this->handle($this->http()->get($this->url(
            "/subscriptions/{$this->subscriptionId}/providers/Microsoft.Compute/locations/{$region}/vmSizes",
            self::COMPUTE_API_VERSION
        )))['value'] ?? [];

        // Burstable (B-series) only — Azure has hundreds of SKUs per region,
        // most of them far more than a plain VPN node needs; same spirit as
        // LinodeClient::sizes() restricting to Shared-CPU plans.
        $types = array_values(array_filter($types, fn (array $t) => str_starts_with($t['name'] ?? '', 'Standard_B')));

        $prices = $this->retailPrices($region);

        return array_map(fn (array $t) => [
            'slug' => $t['name'],
            'vcpus' => $t['numberOfCores'],
            'memory' => $t['memoryInMB'],
            'disk' => ! empty($t['osDiskSizeInMB']) ? (int) round($t['osDiskSizeInMB'] / 1024) : 30,
            'price_monthly' => $prices[$t['name']] ?? 0,
        ], $types);
    }

    /**
     * Azure's public, unauthenticated Retail Prices API — best-effort only,
     * a lookup failure just leaves price_monthly at 0 rather than blocking
     * the size list.
     */
    protected function retailPrices(string $region): array
    {
        $prices = [];

        try {
            $url = 'https://prices.azure.com/api/retail/prices';
            $query = ['$filter' => "serviceName eq 'Virtual Machines' and armRegionName eq '{$region}' and priceType eq 'Consumption'"];

            for ($page = 0; $page < 5 && $url; $page++) {
                $response = Http::acceptJson()->timeout(15)->get($url, $query);

                if ($response->failed()) {
                    break;
                }

                foreach ($response->json('Items') ?? [] as $item) {
                    $sku = $item['armSkuName'] ?? null;

                    if (! $sku || isset($prices[$sku])
                        || str_contains($item['productName'] ?? '', 'Windows')
                        || str_contains($item['skuName'] ?? '', 'Spot')
                        || str_contains($item['meterName'] ?? '', 'Low Priority')
                        || ($item['unitOfMeasure'] ?? '') !== '1 Hour') {
                        continue;
                    }

                    $prices[$sku] = round(($item['retailPrice'] ?? 0) * 730, 2);
                }

                $url = $response->json('NextPageLink');
                $query = [];
            }
        } catch (Throwable) {
            // best-effort only
        }

        return $prices;
    }

    /**
     * Only a curated set of well-known, stable Ubuntu marketplace image URNs
     * — Azure has no flat "list every image" endpoint the way DO/Linode/Vultr
     * do (images live under publisher/offer/sku/version trees), and
     * FiltersUbuntuImages only keeps Ubuntu-labeled entries anyway.
     */
    protected const UBUNTU_IMAGES = [
        'Canonical:0001-com-ubuntu-server-noble:24_04-lts-gen2:latest' => 'Ubuntu 24.04 LTS',
        'Canonical:0001-com-ubuntu-server-jammy:22_04-lts-gen2:latest' => 'Ubuntu 22.04 LTS',
        'Canonical:0001-com-ubuntu-server-focal:20_04-lts-gen2:latest' => 'Ubuntu 20.04 LTS',
    ];

    public function images(string $type = 'distribution'): array
    {
        if ($type !== 'distribution') {
            return [];
        }

        return array_map(
            fn (string $urn, string $label) => ['slug' => $urn, 'label' => $label],
            array_keys(self::UBUNTU_IMAGES),
            self::UBUNTU_IMAGES
        );
    }

    protected function parseImageReference(string $urn): array
    {
        [$publisher, $offer, $sku, $version] = array_pad(explode(':', $urn), 4, 'latest');

        return compact('publisher', 'offer', 'sku', 'version');
    }

    protected function sanitizeName(string $name): string
    {
        $name = strtolower((string) preg_replace('/[^a-z0-9-]/', '-', strtolower($name)));
        $name = trim($name, '-');

        return substr($name !== '' ? $name : 'srv-'.Str::random(6), 0, 40);
    }

    /**
     * The shared cloud-init script (ServerProvisioningService) sets root's
     * password and turns on password auth, but says nothing about
     * PermitRootLogin — DO/Vultr/Linode's stock cloud images already allow
     * it, but Azure's Canonical images can't be assumed to; this appends an
     * explicit directive so root SSH access works the same way everywhere.
     */
    protected function rootLoginCloudInit(string $existingUserData): string
    {
        $extra = "\nruncmd:\n  - sed -i 's/^#*PermitRootLogin.*/PermitRootLogin yes/' /etc/ssh/sshd_config\n  - systemctl restart ssh || systemctl restart sshd\n";

        return $existingUserData !== '' ? $existingUserData.$extra : "#cloud-config{$extra}";
    }

    protected function resourceGroupPath(): string
    {
        return "/subscriptions/{$this->subscriptionId}/resourceGroups/{$this->resourceGroup}";
    }

    protected function ensureResourceGroup(string $region): void
    {
        $path = $this->resourceGroupPath();

        try {
            $this->handle($this->http()->get($this->url($path, self::RESOURCE_GROUP_API_VERSION)));

            return;
        } catch (ProviderException $e) {
            if ($e->statusCode() !== 404) {
                throw $e;
            }
        }

        $this->handle($this->http()->put($this->url($path, self::RESOURCE_GROUP_API_VERSION), ['location' => $region]));
    }

    /**
     * GET-or-PUT helper for the shared per-region networking resources (NSG,
     * vnet/subnet) — created once and reused by every server in that region,
     * unlike the public IP/NIC which are unique per server.
     */
    protected function ensureResource(string $path, string $apiVersion, array $payload): array
    {
        try {
            $existing = $this->handle($this->http()->get($this->url($path, $apiVersion)));

            if (($existing['properties']['provisioningState'] ?? null) === 'Succeeded') {
                return $existing;
            }
        } catch (ProviderException $e) {
            if ($e->statusCode() !== 404) {
                throw $e;
            }
        }

        $this->handle($this->http()->put($this->url($path, $apiVersion), $payload));
        $this->pollUntilSucceeded($path, $apiVersion);

        return $this->handle($this->http()->get($this->url($path, $apiVersion)));
    }

    /**
     * Per-region NSG (allow-all inbound — matches DO/Linode/Vultr, which
     * have no cloud firewall in front of a server at all) + vnet/subnet,
     * reused across every server created in that region.
     */
    protected function ensureNetworking(string $region): array
    {
        $nsgPath = "{$this->resourceGroupPath()}/providers/Microsoft.Network/networkSecurityGroups/vpnpanel-nsg-{$region}";
        $nsg = $this->ensureResource($nsgPath, self::NETWORK_API_VERSION, [
            'location' => $region,
            'properties' => [
                'securityRules' => [[
                    'name' => 'allow-all-inbound',
                    'properties' => [
                        'priority' => 100,
                        'direction' => 'Inbound',
                        'access' => 'Allow',
                        'protocol' => '*',
                        'sourceAddressPrefix' => '*',
                        'destinationAddressPrefix' => '*',
                        'sourcePortRange' => '*',
                        'destinationPortRange' => '*',
                    ],
                ]],
            ],
        ]);

        $vnetPath = "{$this->resourceGroupPath()}/providers/Microsoft.Network/virtualNetworks/vpnpanel-vnet-{$region}";
        $vnet = $this->ensureResource($vnetPath, self::NETWORK_API_VERSION, [
            'location' => $region,
            'properties' => [
                'addressSpace' => ['addressPrefixes' => ['10.10.0.0/16']],
                'subnets' => [[
                    'name' => 'default',
                    'properties' => [
                        'addressPrefix' => '10.10.0.0/24',
                        'networkSecurityGroup' => ['id' => $nsg['id']],
                    ],
                ]],
            ],
        ]);

        return ['subnet_id' => $vnet['properties']['subnets'][0]['id'] ?? "{$vnetPath}/subnets/default"];
    }

    public function createServer(array $data): array
    {
        $vmName = $this->sanitizeName($data['name'] ?? '');
        $region = $data['region'];

        $this->ensureResourceGroup($region);
        $networking = $this->ensureNetworking($region);

        $pipPath = "{$this->resourceGroupPath()}/providers/Microsoft.Network/publicIPAddresses/{$vmName}-ip";
        $this->handle($this->http()->put($this->url($pipPath, self::NETWORK_API_VERSION), [
            'location' => $region,
            'sku' => ['name' => 'Standard'],
            'properties' => ['publicIPAllocationMethod' => 'Static'],
        ]));
        $this->pollUntilSucceeded($pipPath, self::NETWORK_API_VERSION);
        $pip = $this->handle($this->http()->get($this->url($pipPath, self::NETWORK_API_VERSION)));

        $nicPath = "{$this->resourceGroupPath()}/providers/Microsoft.Network/networkInterfaces/{$vmName}-nic";
        $this->handle($this->http()->put($this->url($nicPath, self::NETWORK_API_VERSION), [
            'location' => $region,
            'properties' => [
                'ipConfigurations' => [[
                    'name' => 'ipconfig1',
                    'properties' => [
                        'subnet' => ['id' => $networking['subnet_id']],
                        'publicIPAddress' => ['id' => $pip['id']],
                    ],
                ]],
            ],
        ]));
        $this->pollUntilSucceeded($nicPath, self::NETWORK_API_VERSION);
        $nic = $this->handle($this->http()->get($this->url($nicPath, self::NETWORK_API_VERSION)));

        $password = $data['root_password'] ?? Str::password(20, symbols: false);

        $vm = $this->handle($this->http()->put($this->url($this->vmSelfPath($vmName), self::COMPUTE_API_VERSION), [
            'location' => $region,
            'properties' => [
                'hardwareProfile' => ['vmSize' => $data['size']],
                'storageProfile' => [
                    'imageReference' => $this->parseImageReference($data['image']),
                    'osDisk' => ['createOption' => 'FromImage', 'managedDisk' => ['storageAccountType' => 'Standard_LRS']],
                ],
                'osProfile' => [
                    'computerName' => $vmName,
                    'adminUsername' => 'azureuser',
                    'adminPassword' => $password,
                    'customData' => base64_encode($this->rootLoginCloudInit($data['user_data'] ?? '')),
                    'linuxConfiguration' => ['disablePasswordAuthentication' => false],
                ],
                'networkProfile' => ['networkInterfaces' => [['id' => $nic['id']]]],
            ],
        ]));

        return [
            'droplet' => $this->normalizeInstance($vm, $pip['properties']['ipAddress'] ?? null),
            'links' => ['actions' => [['id' => $vmName]]],
        ];
    }

    public function listServers(int $page = 1, int $perPage = 20): array
    {
        $all = $this->handle($this->http()->get($this->url(
            "{$this->resourceGroupPath()}/providers/Microsoft.Compute/virtualMachines",
            self::COMPUTE_API_VERSION
        )))['value'] ?? [];

        $offset = ($page - 1) * $perPage;

        // IP resolution needs 2 extra calls per server (NIC then public IP)
        // so it's deliberately skipped at list level — renderServerDetail's
        // single getServer() call resolves it properly for one server at a time.
        return [
            'items' => array_map(fn (array $vm) => $this->normalizeInstance($vm, null), array_slice($all, $offset, $perPage)),
            'has_more' => count($all) > $offset + $perPage,
        ];
    }

    protected function vmSelfPath(int|string $id): string
    {
        return "{$this->resourceGroupPath()}/providers/Microsoft.Compute/virtualMachines/{$id}";
    }

    protected function vmActionPath(int|string $id, string $action): string
    {
        return "{$this->vmSelfPath($id)}/{$action}";
    }

    public function getServer(int|string $id): array
    {
        $vm = $this->handle($this->http()->get($this->url(
            $this->vmSelfPath($id),
            self::COMPUTE_API_VERSION
        ).'&$expand=instanceView'));

        return $this->normalizeInstance($vm, $this->resolvePublicIp($vm));
    }

    protected function resolvePublicIp(array $vm): ?string
    {
        $nicRef = $vm['properties']['networkProfile']['networkInterfaces'][0]['id'] ?? null;

        if (! $nicRef) {
            return null;
        }

        try {
            $nic = $this->handle($this->http()->get($this->url($nicRef, self::NETWORK_API_VERSION)));
            $pipRef = $nic['properties']['ipConfigurations'][0]['properties']['publicIPAddress']['id'] ?? null;

            if (! $pipRef) {
                return null;
            }

            $pip = $this->handle($this->http()->get($this->url($pipRef, self::NETWORK_API_VERSION)));

            return $pip['properties']['ipAddress'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    public function deleteServer(int|string $id): void
    {
        $vm = null;

        try {
            $vm = $this->handle($this->http()->get($this->url($this->vmSelfPath($id), self::COMPUTE_API_VERSION)));
        } catch (Throwable) {
            // if we can't even find it, still attempt the delete below
        }

        $nicRef = $vm['properties']['networkProfile']['networkInterfaces'][0]['id'] ?? null;
        $diskRef = $vm['properties']['storageProfile']['osDisk']['managedDisk']['id'] ?? null;

        $this->handle($this->http()->delete($this->url($this->vmSelfPath($id), self::COMPUTE_API_VERSION)));
        $this->waitForDeletion($this->vmSelfPath($id), self::COMPUTE_API_VERSION);

        $pipRef = null;

        if ($nicRef) {
            try {
                $nic = $this->handle($this->http()->get($this->url($nicRef, self::NETWORK_API_VERSION)));
                $pipRef = $nic['properties']['ipConfigurations'][0]['properties']['publicIPAddress']['id'] ?? null;
            } catch (Throwable) {
            }

            $this->deleteQuietly($nicRef, self::NETWORK_API_VERSION);
        }

        if ($pipRef) {
            $this->deleteQuietly($pipRef, self::NETWORK_API_VERSION);
        }

        if ($diskRef) {
            $this->deleteQuietly($diskRef, self::DISK_API_VERSION);
        }
    }

    /**
     * Best-effort cleanup for the resources deleteServer() orphans after the
     * VM itself is gone — a failure here still leaves the bot's own
     * bookkeeping consistent, it just means a NIC/IP/disk is billed until
     * removed manually in the Azure portal.
     */
    protected function deleteQuietly(string $resourceId, string $apiVersion): void
    {
        try {
            $this->handle($this->http()->delete($this->url($resourceId, $apiVersion)));
        } catch (Throwable) {
        }
    }

    protected function pollUntilSucceeded(string $path, string $apiVersion, int $maxAttempts = 15, int $delaySeconds = 3): void
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            try {
                $resource = $this->handle($this->http()->get($this->url($path, $apiVersion)));
            } catch (Throwable) {
                return; // resource gone or a transient error — don't block creation on this
            }

            if (($resource['properties']['provisioningState'] ?? null) === 'Succeeded') {
                return;
            }

            sleep($delaySeconds);
        }
    }

    protected function waitForDeletion(string $path, string $apiVersion, int $maxAttempts = 15, int $delaySeconds = 3): void
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            try {
                $this->handle($this->http()->get($this->url($path, $apiVersion)));
            } catch (ProviderException $e) {
                if ($e->statusCode() === 404) {
                    return;
                }
            }

            sleep($delaySeconds);
        }
    }

    public function powerOn(int|string $id): array
    {
        $this->handle($this->http()->post($this->url($this->vmActionPath($id, 'start'), self::COMPUTE_API_VERSION), (object) []));

        return ['id' => $id];
    }

    public function powerOff(int|string $id): array
    {
        // Azure's "Power Off" (stop) — NOT "Deallocate" — to match DO/Linode/
        // Vultr's shutdown semantics: the OS turns off but the compute
        // reservation (and this server's IP) stays exactly as it was.
        $this->handle($this->http()->post($this->url($this->vmActionPath($id, 'powerOff'), self::COMPUTE_API_VERSION), (object) []));

        return ['id' => $id];
    }

    public function reboot(int|string $id): array
    {
        $this->handle($this->http()->post($this->url($this->vmActionPath($id, 'restart'), self::COMPUTE_API_VERSION), (object) []));

        return ['id' => $id];
    }

    public function resize(int|string $id, string $size, bool $resizeDisk): array
    {
        // A "stopped" (Power Off) VM often still refuses an in-place size
        // change — only a fully "deallocated" VM reliably accepts one.
        // Deallocating an already-deallocated VM is a harmless no-op, so
        // this always deallocates first regardless of how it was powered off.
        $this->handle($this->http()->post($this->url($this->vmActionPath($id, 'deallocate'), self::COMPUTE_API_VERSION), (object) []));
        $this->pollUntilSucceeded($this->vmSelfPath($id), self::COMPUTE_API_VERSION, maxAttempts: 30);

        $this->handle($this->http()->patch($this->url($this->vmSelfPath($id), self::COMPUTE_API_VERSION), [
            'properties' => ['hardwareProfile' => ['vmSize' => $size]],
        ]));

        return ['id' => $id];
    }

    public function rebuild(int|string $id, string $image): array
    {
        throw new ProviderException('ریبیلد سیستم‌عامل برای سرورهای Azure در حال حاضر پشتیبانی نمی‌شود.');
    }

    public function getAction(int|string $actionId): array
    {
        try {
            $vm = $this->handle($this->http()->get($this->url($this->vmSelfPath($actionId), self::COMPUTE_API_VERSION)));
        } catch (ProviderException) {
            // deleted mid-poll (e.g. a failed create got cleaned up) — treat as done
            return ['status' => 'completed', 'resource_type' => 'droplet', 'resource_id' => $actionId];
        }

        $state = $vm['properties']['provisioningState'] ?? 'Succeeded';
        $transitional = in_array($state, ['Creating', 'Updating', 'Deleting'], true);

        return [
            'status' => $transitional ? 'in-progress' : 'completed',
            'resource_type' => 'droplet',
            'resource_id' => $vm['name'] ?? $actionId,
        ];
    }

    protected function normalizeInstance(array $vm, ?string $ip): array
    {
        return [
            'id' => $vm['name'],
            'name' => $vm['name'],
            'status' => $this->translateStatus($vm['properties']['instanceView']['statuses'] ?? [], $vm['properties']['provisioningState'] ?? null),
            'networks' => ['v4' => $ip ? [['ip_address' => $ip, 'type' => 'public']] : []],
            'region' => ['slug' => $vm['location'] ?? '', 'name' => $vm['location'] ?? '-'],
            'size_slug' => $vm['properties']['hardwareProfile']['vmSize'] ?? '',
            'image' => $this->splitImageReference($vm['properties']['storageProfile']['imageReference'] ?? []),
        ];
    }

    protected function translateStatus(array $statuses, ?string $provisioningState): string
    {
        if ($provisioningState !== null && ! in_array($provisioningState, ['Succeeded'], true)) {
            return $provisioningState === 'Failed' ? 'errored' : 'new';
        }

        foreach ($statuses as $status) {
            if (($status['code'] ?? '') === 'PowerState/running') {
                return 'active';
            }

            if (in_array($status['code'] ?? '', ['PowerState/deallocated', 'PowerState/stopped'], true)) {
                return 'off';
            }
        }

        return 'active'; // no instanceView data (e.g. plain list) — assume running
    }

    protected function splitImageReference(array $ref): array
    {
        if (empty($ref)) {
            return ['distribution' => 'Linux', 'name' => ''];
        }

        return [
            'distribution' => $ref['publisher'] ?? 'Linux',
            'name' => trim(($ref['offer'] ?? '').' '.($ref['sku'] ?? '')),
        ];
    }

    public function listReservedIps(int|string|null $dropletId = null): array
    {
        throw new ProviderException('آی‌پی رزرو برای سرورهای Azure در حال حاضر پشتیبانی نمی‌شود.');
    }

    public function allocateReservedIp(string $region, int|string|null $dropletId = null): array
    {
        throw new ProviderException('آی‌پی رزرو برای سرورهای Azure در حال حاضر پشتیبانی نمی‌شود.');
    }

    public function assignReservedIp(string $ip, int|string $dropletId): array
    {
        throw new ProviderException('آی‌پی رزرو برای سرورهای Azure در حال حاضر پشتیبانی نمی‌شود.');
    }

    public function unassignReservedIp(string $ip): array
    {
        throw new ProviderException('آی‌پی رزرو برای سرورهای Azure در حال حاضر پشتیبانی نمی‌شود.');
    }

    public function releaseReservedIp(string $ip): void
    {
        throw new ProviderException('آی‌پی رزرو برای سرورهای Azure در حال حاضر پشتیبانی نمی‌شود.');
    }
}
