<?php

namespace Tests\Feature;

use App\Services\Providers\ProviderException;
use App\Services\Providers\Vultr\VultrClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VultrClientTest extends TestCase
{
    use RefreshDatabase;

    protected function client(): VultrClient
    {
        return new VultrClient('fake-token');
    }

    public function test_account_returns_the_response_body(): void
    {
        Http::fake([
            'api.vultr.com/v2/account' => Http::response([
                'account' => ['email' => 'owner@example.com', 'name' => 'Owner'],
            ]),
        ]);

        $this->assertSame('owner@example.com', $this->client()->account()['email']);
    }

    public function test_account_throws_on_failure_with_the_error_message(): void
    {
        Http::fake([
            'api.vultr.com/v2/account' => Http::response(['error' => 'Invalid API key.'], 401),
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Invalid API key.');

        $this->client()->account();
    }

    public function test_regions_normalizes_to_the_digitalocean_shape(): void
    {
        Http::fake([
            'api.vultr.com/v2/regions*' => Http::response([
                'regions' => [
                    ['id' => 'ewr', 'city' => 'New Jersey', 'country' => 'us', 'continent' => 'North America', 'options' => []],
                    ['id' => 'fra', 'city' => 'Frankfurt', 'country' => 'de', 'continent' => 'Europe', 'options' => []],
                ],
            ]),
        ]);

        $regions = $this->client()->regions();

        $this->assertCount(2, $regions);
        $this->assertEqualsCanonicalizing(['ewr', 'fra'], array_column($regions, 'slug'));

        $frankfurt = collect($regions)->firstWhere('slug', 'fra');
        $this->assertStringContainsString('🇩🇪', $frankfurt['label']);
    }

    public function test_sizes_normalizes_fields_and_excludes_dedicated_cloud_plans(): void
    {
        Http::fake([
            'api.vultr.com/v2/plans*' => Http::response([
                'plans' => [
                    ['id' => 'vc2-1c-1gb', 'type' => 'vc2', 'vcpu_count' => 1, 'ram' => 1024, 'disk' => 25, 'bandwidth' => 1000, 'monthly_cost' => 6, 'locations' => ['ewr']],
                    ['id' => 'vdc-4c-8gb', 'type' => 'vdc', 'vcpu_count' => 4, 'ram' => 8192, 'disk' => 100, 'bandwidth' => 5000, 'monthly_cost' => 80, 'locations' => ['ewr']],
                ],
            ]),
        ]);

        $sizes = $this->client()->sizes();

        $this->assertCount(1, $sizes);
        $this->assertSame('vc2-1c-1gb', $sizes[0]['slug']);
        $this->assertSame(1, $sizes[0]['vcpus']);
        $this->assertSame(1024, $sizes[0]['memory']);
        $this->assertSame(6, $sizes[0]['price_monthly']);
    }

    public function test_images_filters_out_non_distribution_families(): void
    {
        Http::fake([
            'api.vultr.com/v2/os*' => Http::response([
                'os' => [
                    ['id' => 2136, 'name' => 'Debian 12 x64', 'arch' => 'x64', 'family' => 'debian'],
                    ['id' => 159, 'name' => 'Custom', 'arch' => 'x64', 'family' => 'custom'],
                ],
            ]),
        ]);

        $images = $this->client()->images('distribution');

        $this->assertCount(1, $images);
        $this->assertSame('2136', $images[0]['slug']);
        $this->assertSame('Debian 12 x64', $images[0]['label']);
    }

    public function test_create_server_sends_the_vultr_shaped_payload_and_normalizes_the_response(): void
    {
        Http::fake([
            'api.vultr.com/v2/instances' => Http::response([
                'instance' => [
                    'id' => 'abc-123',
                    'label' => 'my-server-1',
                    'hostname' => 'my-server-1',
                    'status' => 'pending',
                    'power_status' => 'running',
                    'main_ip' => '0.0.0.0',
                    'region' => 'ewr',
                    'plan' => 'vc2-1c-1gb',
                    'os' => 'Debian 12 x64',
                ],
            ], 202),
        ]);

        $result = $this->client()->createServer([
            'name' => 'my-server-1',
            'region' => 'ewr',
            'size' => 'vc2-1c-1gb',
            'image' => '2136',
            'root_password' => 'SuperSecret123',
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains((string) $request->url(), '/instances')
                && $request->method() === 'POST'
                && ($body['region'] ?? null) === 'ewr'
                && ($body['plan'] ?? null) === 'vc2-1c-1gb'
                && ($body['os_id'] ?? null) === 2136
                && isset($body['user_data'])
                && str_contains(base64_decode($body['user_data']), 'SuperSecret123');
        });

        $this->assertSame('abc-123', $result['droplet']['id']);
        $this->assertSame('my-server-1', $result['droplet']['name']);
        $this->assertSame('new', $result['droplet']['status']); // still "pending" on Vultr's side
        $this->assertSame('abc-123', $result['links']['actions'][0]['id']);
    }

    public function test_list_servers_follows_cursor_pages_and_normalizes_power_state(): void
    {
        // A single listServers() call walks every cursor page itself, so one
        // sequence (2 pushes) is exactly the 2 HTTP requests it should make.
        Http::fake([
            'api.vultr.com/v2/instances*' => Http::sequence()
                ->push([
                    'instances' => [
                        ['id' => '1', 'label' => 'srv-1', 'status' => 'active', 'power_status' => 'running', 'main_ip' => '203.0.113.1', 'region' => 'ewr', 'plan' => 'vc2-1c-1gb', 'os' => 'Debian 12 x64'],
                    ],
                    'meta' => ['links' => ['next' => 'cursor-2']],
                ])
                ->push([
                    'instances' => [
                        ['id' => '2', 'label' => 'srv-2', 'status' => 'active', 'power_status' => 'stopped', 'main_ip' => '203.0.113.2', 'region' => 'ewr', 'plan' => 'vc2-1c-1gb', 'os' => 'Debian 12 x64'],
                    ],
                    'meta' => ['links' => ['next' => '']],
                ]),
        ]);

        $result = $this->client()->listServers(1, 20);

        $this->assertCount(2, $result['items']);
        $this->assertSame('srv-1', $result['items'][0]['name']);
        $this->assertSame('active', $result['items'][0]['status']);
        $this->assertSame('srv-2', $result['items'][1]['name']);
        $this->assertSame('off', $result['items'][1]['status']);
        $this->assertFalse($result['has_more']);
    }

    public function test_list_servers_paginates_client_side_by_page_and_per_page(): void
    {
        // Not a sequence — Http::fake() with a plain response is reusable
        // across every matching request, which is what's needed here since
        // listServers() re-fetches the (unpaginated-at-the-API-level) full
        // list on every call.
        Http::fake([
            'api.vultr.com/v2/instances*' => Http::response([
                'instances' => [
                    ['id' => '1', 'label' => 'srv-1', 'status' => 'active', 'power_status' => 'running', 'main_ip' => '203.0.113.1', 'region' => 'ewr', 'plan' => 'vc2-1c-1gb', 'os' => 'Debian 12 x64'],
                    ['id' => '2', 'label' => 'srv-2', 'status' => 'active', 'power_status' => 'running', 'main_ip' => '203.0.113.2', 'region' => 'ewr', 'plan' => 'vc2-1c-1gb', 'os' => 'Debian 12 x64'],
                    ['id' => '3', 'label' => 'srv-3', 'status' => 'active', 'power_status' => 'running', 'main_ip' => '203.0.113.3', 'region' => 'ewr', 'plan' => 'vc2-1c-1gb', 'os' => 'Debian 12 x64'],
                ],
                'meta' => ['links' => ['next' => '']],
            ]),
        ]);

        $first = $this->client()->listServers(1, 2);
        $this->assertSame(['srv-1', 'srv-2'], array_column($first['items'], 'name'));
        $this->assertTrue($first['has_more']);

        $second = $this->client()->listServers(2, 2);
        $this->assertSame(['srv-3'], array_column($second['items'], 'name'));
        $this->assertFalse($second['has_more']);
    }

    public function test_get_action_reports_in_progress_while_transitional_and_completed_once_stable(): void
    {
        Http::fake([
            'api.vultr.com/v2/instances/abc-123' => Http::sequence()
                ->push(['instance' => ['id' => 'abc-123', 'status' => 'active', 'server_status' => 'installingbooting']])
                ->push(['instance' => ['id' => 'abc-123', 'status' => 'active', 'server_status' => 'ok']]),
        ]);

        $first = $this->client()->getAction('abc-123');
        $this->assertSame('in-progress', $first['status']);

        $second = $this->client()->getAction('abc-123');
        $this->assertSame('completed', $second['status']);
        $this->assertSame('droplet', $second['resource_type']);
        $this->assertSame('abc-123', $second['resource_id']);
    }

    public function test_get_server_enriches_size_from_the_plans_lookup(): void
    {
        Http::fake([
            'api.vultr.com/v2/instances/abc-123' => Http::response([
                'instance' => ['id' => 'abc-123', 'label' => 'srv', 'status' => 'active', 'power_status' => 'running', 'main_ip' => '203.0.113.10', 'region' => 'ewr', 'plan' => 'vc2-1c-1gb', 'os' => 'Debian 12 x64'],
            ]),
            'api.vultr.com/v2/plans*' => Http::response([
                'plans' => [
                    ['id' => 'vc2-1c-1gb', 'type' => 'vc2', 'vcpu_count' => 1, 'ram' => 1024, 'disk' => 25, 'bandwidth' => 1000, 'monthly_cost' => 6, 'locations' => ['ewr']],
                ],
            ]),
        ]);

        $server = $this->client()->getServer('abc-123');

        $this->assertSame('active', $server['status']);
        $this->assertSame(1, $server['size']['vcpus']);
        $this->assertSame(6, $server['size']['price_monthly']);
        $this->assertSame('Debian 12 x64', $server['image']['distribution']);
    }

    public function test_list_reserved_ips_filters_by_instance_id(): void
    {
        Http::fake([
            'api.vultr.com/v2/reserved-ips*' => Http::response([
                'reserved_ips' => [
                    ['id' => 'rip-1', 'subnet' => '203.0.113.50', 'region' => 'ewr', 'instance_id' => 'abc-123'],
                    ['id' => 'rip-2', 'subnet' => '203.0.113.51', 'region' => 'ewr', 'instance_id' => 'other-id'],
                ],
            ]),
        ]);

        $reserved = $this->client()->listReservedIps('abc-123');

        $this->assertCount(1, $reserved);
        $this->assertSame('203.0.113.50', $reserved[0]['ip']);
    }

    public function test_allocate_reserved_ip_does_not_require_a_droplet_id(): void
    {
        Http::fake([
            'api.vultr.com/v2/reserved-ips' => Http::response([
                'reserved_ip' => ['id' => 'rip-1', 'subnet' => '203.0.113.50', 'region' => 'ewr'],
            ], 201),
        ]);

        $result = $this->client()->allocateReservedIp('ewr');

        $this->assertSame('203.0.113.50', $result['reserved_ip']['ip']);
    }
}
