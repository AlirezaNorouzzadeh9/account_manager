<?php

namespace Tests\Feature;

use App\Services\Providers\Azure\AzureClient;
use App\Services\Providers\ProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AzureClientTest extends TestCase
{
    use RefreshDatabase;

    protected function client(): AzureClient
    {
        return new AzureClient('fake-secret', 'tenant-1', 'client-1', 'sub-1', 'my-rg');
    }

    protected function fakeToken(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'fake-token',
                'expires_in' => 3600,
            ]),
        ]);
    }

    public function test_account_exchanges_client_credentials_and_returns_subscription_info(): void
    {
        $this->fakeToken();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'management.azure.com/subscriptions/sub-1?*' => Http::response([
                'subscriptionId' => 'sub-1',
                'displayName' => 'My Subscription',
            ]),
        ]);

        $account = $this->client()->account();

        $this->assertSame('My Subscription', $account['email']);
        $this->assertSame('sub-1', $account['uuid']);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'login.microsoftonline.com/tenant-1')
            && $request['client_id'] === 'client-1'
            && $request['client_secret'] === 'fake-secret'
            && $request['grant_type'] === 'client_credentials');
    }

    public function test_account_throws_on_bad_credentials(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'error' => 'invalid_client',
                'error_description' => "AADSTS7000215: Invalid client secret.\r\nTrace ID: xxx",
            ], 401),
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('AADSTS7000215: Invalid client secret.');

        $this->client()->account();
    }

    public function test_regions_keeps_only_physical_locations_and_sorts_by_label(): void
    {
        $this->fakeToken();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'management.azure.com/subscriptions/sub-1/locations*' => Http::response([
                'value' => [
                    ['name' => 'westeurope', 'displayName' => 'West Europe', 'metadata' => ['regionType' => 'Physical']],
                    ['name' => 'eastus', 'displayName' => 'East US', 'metadata' => ['regionType' => 'Physical']],
                    ['name' => 'someregionpair', 'displayName' => 'Region Pair', 'metadata' => ['regionType' => 'Logical']],
                ],
            ]),
        ]);

        $regions = $this->client()->regions();

        $this->assertCount(2, $regions);
        $this->assertSame(['eastus', 'westeurope'], collect($regions)->pluck('slug')->sort()->values()->all());
        $this->assertSame($regions, collect($regions)->sortBy('label')->values()->all());

        $bySlug = collect($regions)->keyBy('slug');
        $this->assertStringContainsString('🇺🇸', $bySlug['eastus']['label']);
        $this->assertStringContainsString('🇳🇱', $bySlug['westeurope']['label']);
    }

    public function test_sizes_only_returns_burstable_plans_and_attaches_retail_price(): void
    {
        $this->fakeToken();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'management.azure.com/subscriptions/sub-1/providers/Microsoft.Compute/locations/eastus/vmSizes*' => Http::response([
                'value' => [
                    ['name' => 'Standard_B1s', 'numberOfCores' => 1, 'memoryInMB' => 1024, 'osDiskSizeInMB' => 30720],
                    ['name' => 'Standard_D2s_v3', 'numberOfCores' => 2, 'memoryInMB' => 8192, 'osDiskSizeInMB' => 30720],
                ],
            ]),
            'prices.azure.com/api/retail/prices*' => Http::response([
                'Items' => [
                    ['armSkuName' => 'Standard_B1s', 'productName' => 'Virtual Machines B Series', 'skuName' => 'B1s', 'meterName' => 'B1s', 'unitOfMeasure' => '1 Hour', 'retailPrice' => 0.01],
                    ['armSkuName' => 'Standard_B1s', 'productName' => 'Virtual Machines B Series Windows', 'skuName' => 'B1s', 'meterName' => 'B1s', 'unitOfMeasure' => '1 Hour', 'retailPrice' => 0.02],
                ],
                'NextPageLink' => null,
            ]),
        ]);

        $sizes = $this->client()->sizes('eastus');

        $this->assertCount(1, $sizes);
        $this->assertSame('Standard_B1s', $sizes[0]['slug']);
        $this->assertSame(1, $sizes[0]['vcpus']);
        $this->assertSame(7.3, $sizes[0]['price_monthly']);
    }

    public function test_sizes_throws_without_a_region(): void
    {
        $this->expectException(ProviderException::class);

        $this->client()->sizes();
    }

    public function test_images_returns_the_curated_ubuntu_list(): void
    {
        $images = $this->client()->images('distribution');

        $this->assertNotEmpty($images);

        foreach ($images as $image) {
            $this->assertStringContainsString('Ubuntu', $image['label']);
            $this->assertStringStartsWith('Canonical:', $image['slug']);
        }
    }

    public function test_create_server_provisions_networking_then_the_vm(): void
    {
        $this->fakeToken();

        $succeeded = ['id' => '/fake/id', 'properties' => ['provisioningState' => 'Succeeded']];

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg?*' => Http::response($succeeded),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/networkSecurityGroups/*' => Http::response(array_merge($succeeded, [
                'id' => '/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/networkSecurityGroups/vpnpanel-nsg-eastus',
            ])),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/virtualNetworks/*' => Http::response([
                'id' => '/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/virtualNetworks/vpnpanel-vnet-eastus',
                'properties' => [
                    'provisioningState' => 'Succeeded',
                    'subnets' => [['id' => '/subscriptions/sub-1/.../subnets/default']],
                ],
            ]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/publicIPAddresses/*' => Http::response([
                'id' => '/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/publicIPAddresses/my-server-ip',
                'properties' => ['provisioningState' => 'Succeeded', 'ipAddress' => '20.1.2.3'],
            ]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/networkInterfaces/*' => Http::response([
                'id' => '/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/networkInterfaces/my-server-nic',
                'properties' => ['provisioningState' => 'Succeeded'],
            ]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Compute/virtualMachines/*' => Http::response([
                'name' => 'my-server',
                'location' => 'eastus',
                'properties' => [
                    'provisioningState' => 'Creating',
                    'hardwareProfile' => ['vmSize' => 'Standard_B1s'],
                    'storageProfile' => ['imageReference' => ['publisher' => 'Canonical', 'offer' => '0001-com-ubuntu-server-jammy', 'sku' => '22_04-lts-gen2']],
                ],
            ]),
        ]);

        $result = $this->client()->createServer([
            'name' => 'my-server',
            'region' => 'eastus',
            'size' => 'Standard_B1s',
            'image' => 'Canonical:0001-com-ubuntu-server-jammy:22_04-lts-gen2:latest',
            'user_data' => "#cloud-config\nchpasswd:\n  expire: false\n  list: |\n    root:secret\nssh_pwauth: true\n",
            'root_password' => 'secret',
        ]);

        $this->assertSame('my-server', $result['droplet']['id']);
        $this->assertSame('20.1.2.3', $result['droplet']['networks']['v4'][0]['ip_address']);
        $this->assertSame('my-server', $result['links']['actions'][0]['id']);

        Http::assertSent(function ($request) {
            if (! str_contains((string) $request->url(), '/virtualMachines/my-server?') || $request->method() !== 'PUT') {
                return false;
            }

            $body = $request->data();

            return $body['properties']['osProfile']['adminUsername'] === 'azureuser'
                && $body['properties']['osProfile']['adminPassword'] === 'secret'
                && $body['properties']['hardwareProfile']['vmSize'] === 'Standard_B1s'
                && $body['properties']['storageProfile']['imageReference']['offer'] === '0001-com-ubuntu-server-jammy'
                && str_contains(base64_decode($body['properties']['osProfile']['customData']), 'PermitRootLogin yes');
        });

        // NSG/vnet already report provisioningState=Succeeded in this fake
        // (the common case: reused from an earlier server in the same
        // region), so only the always-created-per-server PIP/NIC PUTs fire.
        Http::assertSent(function ($request) {
            if (! str_contains((string) $request->url(), 'publicIPAddresses/my-server-ip?') || $request->method() !== 'PUT') {
                return false;
            }

            $body = $request->data();

            return $body['sku']['name'] === 'Standard' && $body['properties']['publicIPAllocationMethod'] === 'Static';
        });

        Http::assertSent(function ($request) {
            if (! str_contains((string) $request->url(), 'networkInterfaces/my-server-nic?') || $request->method() !== 'PUT') {
                return false;
            }

            $config = $request->data()['properties']['ipConfigurations'][0]['properties'];

            return $config['subnet']['id'] === '/subscriptions/sub-1/.../subnets/default'
                && str_contains($config['publicIPAddress']['id'], 'publicIPAddresses/my-server-ip');
        });
    }

    public function test_create_server_creates_the_resource_group_when_it_does_not_exist_yet(): void
    {
        $this->fakeToken();

        $succeeded = ['id' => '/fake/id', 'properties' => ['provisioningState' => 'Succeeded']];

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            // 1) existence check (404) then 2) the PUT create call itself
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg?*' => Http::sequence()
                ->push([], 404)
                ->push($succeeded),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/networkSecurityGroups/*' => Http::response(array_merge($succeeded, [
                'id' => '/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/networkSecurityGroups/vpnpanel-nsg-eastus',
            ])),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/virtualNetworks/*' => Http::response([
                'id' => '/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/virtualNetworks/vpnpanel-vnet-eastus',
                'properties' => ['provisioningState' => 'Succeeded', 'subnets' => [['id' => '/subscriptions/sub-1/.../subnets/default']]],
            ]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/publicIPAddresses/*' => Http::response([
                'id' => '/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/publicIPAddresses/my-server-ip',
                'properties' => ['provisioningState' => 'Succeeded', 'ipAddress' => '20.1.2.3'],
            ]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/networkInterfaces/*' => Http::response([
                'id' => '/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/networkInterfaces/my-server-nic',
                'properties' => ['provisioningState' => 'Succeeded'],
            ]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Compute/virtualMachines/*' => Http::response([
                'name' => 'my-server', 'location' => 'eastus',
                'properties' => ['provisioningState' => 'Creating', 'hardwareProfile' => ['vmSize' => 'Standard_B1s'], 'storageProfile' => ['imageReference' => []]],
            ]),
        ]);

        $this->client()->createServer([
            'name' => 'my-server',
            'region' => 'eastus',
            'size' => 'Standard_B1s',
            'image' => 'Canonical:0001-com-ubuntu-server-jammy:22_04-lts-gen2:latest',
        ]);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/resourceGroups/my-rg?')
            && $request->method() === 'PUT'
            && $request->data()['location'] === 'eastus');
    }

    public function test_power_actions_send_a_json_object_body_not_an_array(): void
    {
        $this->fakeToken();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Compute/virtualMachines/my-server/start*' => Http::response([]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Compute/virtualMachines/my-server/powerOff*' => Http::response([]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Compute/virtualMachines/my-server/restart*' => Http::response([]),
        ]);

        $this->client()->powerOn('my-server');
        $this->client()->powerOff('my-server');
        $this->client()->reboot('my-server');

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/start') && $request->body() === '{}');
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/powerOff') && $request->body() === '{}');
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/restart') && $request->body() === '{}');
    }

    public function test_resize_deallocates_before_patching_the_size(): void
    {
        $this->fakeToken();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Compute/virtualMachines/my-server/deallocate*' => Http::response([]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Compute/virtualMachines/my-server?*' => Http::response([
                'properties' => ['provisioningState' => 'Succeeded'],
            ]),
        ]);

        $this->client()->resize('my-server', 'Standard_B2s', false);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/deallocate') && $request->method() === 'POST');
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request->data()['properties']['hardwareProfile']['vmSize'] === 'Standard_B2s');
    }

    public function test_rebuild_is_not_supported(): void
    {
        $this->expectException(ProviderException::class);

        $this->client()->rebuild('my-server', 'some-image');
    }

    public function test_reserved_ip_methods_are_not_supported(): void
    {
        $client = $this->client();

        foreach (['listReservedIps', 'allocateReservedIp', 'assignReservedIp', 'unassignReservedIp', 'releaseReservedIp'] as $method) {
            try {
                match ($method) {
                    'listReservedIps' => $client->listReservedIps(),
                    'allocateReservedIp' => $client->allocateReservedIp('eastus'),
                    'assignReservedIp' => $client->assignReservedIp('1.2.3.4', 'my-server'),
                    'unassignReservedIp' => $client->unassignReservedIp('1.2.3.4'),
                    'releaseReservedIp' => $client->releaseReservedIp('1.2.3.4'),
                };
                $this->fail("{$method} was expected to throw a ProviderException");
            } catch (ProviderException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_get_server_resolves_the_public_ip_through_the_nic(): void
    {
        $this->fakeToken();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Compute/virtualMachines/my-server?*' => Http::response([
                'name' => 'my-server',
                'location' => 'eastus',
                'properties' => [
                    'provisioningState' => 'Succeeded',
                    'hardwareProfile' => ['vmSize' => 'Standard_B1s'],
                    'storageProfile' => ['imageReference' => ['publisher' => 'Canonical', 'offer' => '0001-com-ubuntu-server-jammy', 'sku' => '22_04-lts-gen2']],
                    'networkProfile' => ['networkInterfaces' => [['id' => '/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/networkInterfaces/my-server-nic']]],
                    'instanceView' => ['statuses' => [['code' => 'PowerState/running']]],
                ],
            ]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/networkInterfaces/my-server-nic*' => Http::response([
                'properties' => ['ipConfigurations' => [['properties' => ['publicIPAddress' => ['id' => '/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/publicIPAddresses/my-server-ip']]]]],
            ]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/publicIPAddresses/my-server-ip*' => Http::response([
                'properties' => ['ipAddress' => '20.1.2.3'],
            ]),
        ]);

        $server = $this->client()->getServer('my-server');

        $this->assertSame('active', $server['status']);
        $this->assertSame('20.1.2.3', $server['networks']['v4'][0]['ip_address']);
    }

    public function test_delete_server_removes_the_vm_nic_ip_and_disk(): void
    {
        $this->fakeToken();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Compute/virtualMachines/my-server?*' => Http::sequence()
                // 1) initial GET before delete (reads nic/disk refs)
                ->push([
                    'properties' => [
                        'networkProfile' => ['networkInterfaces' => [['id' => '/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/networkInterfaces/my-server-nic']]],
                        'storageProfile' => ['osDisk' => ['managedDisk' => ['id' => '/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Compute/disks/my-server-disk']]],
                    ],
                ])
                // 2) the DELETE request itself (Http::fake matches by URL, not method)
                ->push([])
                // 3) waitForDeletion's confirmation GET
                ->push([], 404),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/networkInterfaces/my-server-nic*' => Http::response([
                'properties' => ['ipConfigurations' => [['properties' => ['publicIPAddress' => ['id' => '/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/publicIPAddresses/my-server-ip']]]]],
            ]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Network/publicIPAddresses/my-server-ip*' => Http::response([]),
            'management.azure.com/subscriptions/sub-1/resourceGroups/my-rg/providers/Microsoft.Compute/disks/my-server-disk*' => Http::response([]),
        ]);

        $this->client()->deleteServer('my-server');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains((string) $request->url(), '/virtualMachines/my-server?'));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains((string) $request->url(), '/networkInterfaces/my-server-nic'));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains((string) $request->url(), '/publicIPAddresses/my-server-ip'));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains((string) $request->url(), '/disks/my-server-disk'));
    }
}
