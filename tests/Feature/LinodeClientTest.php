<?php

namespace Tests\Feature;

use App\Services\Providers\Linode\LinodeClient;
use App\Services\Providers\ProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinodeClientTest extends TestCase
{
    use RefreshDatabase;

    protected function client(): LinodeClient
    {
        return new LinodeClient('fake-token');
    }

    public function test_account_returns_the_response_body(): void
    {
        Http::fake([
            'api.linode.com/v4/account' => Http::response(['email' => 'owner@example.com']),
        ]);

        $this->assertSame('owner@example.com', $this->client()->account()['email']);
    }

    public function test_account_throws_on_failure_with_the_error_reason(): void
    {
        Http::fake([
            'api.linode.com/v4/account' => Http::response([
                'errors' => [['reason' => 'Invalid Token']],
            ], 401),
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Invalid Token');

        $this->client()->account();
    }

    public function test_regions_normalizes_to_the_digitalocean_shape(): void
    {
        Http::fake([
            'api.linode.com/v4/regions' => Http::response([
                'data' => [
                    ['id' => 'us-east', 'label' => 'Newark, NJ, USA', 'country' => 'us'],
                    ['id' => 'fr-par', 'label' => 'Paris, FR', 'country' => 'fr'],
                ],
            ]),
        ]);

        $regions = $this->client()->regions();

        $this->assertCount(2, $regions);
        $this->assertEqualsCanonicalizing(['us-east', 'fr-par'], array_column($regions, 'slug'));

        $paris = collect($regions)->firstWhere('slug', 'fr-par');
        $this->assertSame('Paris, FR', $paris['name']);
        $this->assertStringContainsString('🇫🇷', $paris['label']);
    }

    public function test_sizes_normalizes_fields_and_excludes_gpu_types(): void
    {
        Http::fake([
            'api.linode.com/v4/linode/types' => Http::response([
                'data' => [
                    ['id' => 'g6-standard-2', 'class' => 'standard', 'vcpus' => 2, 'memory' => 4096, 'disk' => 81920, 'price' => ['monthly' => 24, 'hourly' => 0.036]],
                    ['id' => 'g1-gpu-rtx6000-1', 'class' => 'gpu', 'vcpus' => 8, 'memory' => 32768, 'disk' => 819200, 'price' => ['monthly' => 1000, 'hourly' => 1.5]],
                ],
            ]),
        ]);

        $sizes = $this->client()->sizes();

        $this->assertCount(1, $sizes);
        $this->assertSame('g6-standard-2', $sizes[0]['slug']);
        $this->assertSame(2, $sizes[0]['vcpus']);
        $this->assertSame(4096, $sizes[0]['memory']);
        $this->assertSame(80, $sizes[0]['disk']);
        $this->assertSame(24, $sizes[0]['price_monthly']);
    }

    public function test_images_filters_by_distribution_vs_private(): void
    {
        Http::fake([
            'api.linode.com/v4/images' => Http::response([
                'data' => [
                    ['id' => 'linode/debian12', 'label' => 'Debian 12'],
                    ['id' => 'private/12345', 'label' => 'My Snapshot'],
                ],
            ]),
        ]);

        $distros = $this->client()->images('distribution');
        $this->assertCount(1, $distros);
        $this->assertSame('linode/debian12', $distros[0]['slug']);
        $this->assertSame('Debian 12', $distros[0]['label']);

        $private = $this->client()->images('private');
        $this->assertCount(1, $private);
        $this->assertSame('private/12345', $private[0]['slug']);
    }

    public function test_images_excludes_kubernetes_cluster_node_images(): void
    {
        Http::fake([
            'api.linode.com/v4/images' => Http::response([
                'data' => [
                    ['id' => 'linode/debian12', 'label' => 'Debian 12'],
                    ['id' => 'linode/kubernetes1.30', 'label' => 'Kubernetes 1.30.3 on Debian 12'],
                ],
            ]),
        ]);

        $distros = $this->client()->images('distribution');

        $this->assertCount(1, $distros);
        $this->assertSame('linode/debian12', $distros[0]['slug']);
    }

    public function test_create_server_sends_the_linode_shaped_payload_and_normalizes_the_response(): void
    {
        Http::fake([
            'api.linode.com/v4/linode/instances' => Http::response([
                'id' => 999,
                'label' => 'my-server-1',
                'status' => 'provisioning',
                'ipv4' => ['203.0.113.10'],
                'region' => 'us-east',
                'type' => 'g6-standard-2',
                'image' => 'linode/debian12',
            ], 200),
        ]);

        $result = $this->client()->createServer([
            'name' => 'my-server-1',
            'region' => 'us-east',
            'size' => 'g6-standard-2',
            'image' => 'linode/debian12',
            'user_data' => "#cloud-config\n...",
            'root_password' => 'SuperSecret123',
        ]);

        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), '/linode/instances')
                && $request->method() === 'POST'
                && ($request['label'] ?? null) === 'my-server-1'
                && ($request['region'] ?? null) === 'us-east'
                && ($request['type'] ?? null) === 'g6-standard-2'
                && ($request['image'] ?? null) === 'linode/debian12'
                && ($request['root_pass'] ?? null) === 'SuperSecret123'
                && ! array_key_exists('user_data', $request->data());
        });

        $this->assertSame(999, $result['droplet']['id']);
        $this->assertSame('my-server-1', $result['droplet']['name']);
        $this->assertSame('provisioning', $result['droplet']['status']); // no DO equivalent, passed through as-is
        $this->assertSame('203.0.113.10', $result['droplet']['networks']['v4'][0]['ip_address']);
        $this->assertSame(999, $result['links']['actions'][0]['id']);
    }

    public function test_list_servers_normalizes_items_and_computes_has_more_from_pages(): void
    {
        Http::fake([
            'api.linode.com/v4/linode/instances*' => Http::response([
                'data' => [
                    ['id' => 1, 'label' => 'srv-1', 'status' => 'running', 'ipv4' => ['203.0.113.1'], 'region' => 'us-east', 'type' => 'g6-standard-2', 'image' => 'linode/debian12'],
                ],
                'page' => 1,
                'pages' => 2,
                'results' => 15,
            ]),
        ]);

        $result = $this->client()->listServers(1, 8);

        $this->assertCount(1, $result['items']);
        $this->assertSame('srv-1', $result['items'][0]['name']);
        $this->assertSame('active', $result['items'][0]['status']);
        $this->assertTrue($result['has_more']);
    }

    public function test_get_action_reports_in_progress_while_transitional_and_completed_once_stable(): void
    {
        Http::fake([
            'api.linode.com/v4/linode/instances/999' => Http::sequence()
                ->push(['id' => 999, 'status' => 'booting'])
                ->push(['id' => 999, 'status' => 'running']),
        ]);

        $first = $this->client()->getAction(999);
        $this->assertSame('in-progress', $first['status']);

        $second = $this->client()->getAction(999);
        $this->assertSame('completed', $second['status']);
        $this->assertSame('droplet', $second['resource_type']);
        $this->assertSame(999, $second['resource_id']);
    }

    public function test_get_server_enriches_size_from_the_type_lookup(): void
    {
        Http::fake([
            'api.linode.com/v4/linode/instances/999' => Http::response([
                'id' => 999, 'label' => 'srv', 'status' => 'running', 'ipv4' => ['203.0.113.10'],
                'region' => 'us-east', 'type' => 'g6-standard-2', 'image' => 'linode/debian12',
            ]),
            'api.linode.com/v4/linode/types/g6-standard-2' => Http::response([
                'id' => 'g6-standard-2', 'vcpus' => 2, 'memory' => 4096, 'disk' => 81920, 'price' => ['monthly' => 24],
            ]),
        ]);

        $server = $this->client()->getServer(999);

        $this->assertSame('active', $server['status']);
        $this->assertSame(2, $server['size']['vcpus']);
        $this->assertSame(24, $server['size']['price_monthly']);
        $this->assertSame('Linode', $server['image']['distribution']);
        $this->assertSame('debian12', $server['image']['name']);
    }

    public function test_list_reserved_ips_returns_every_public_ip_after_the_primary(): void
    {
        Http::fake([
            'api.linode.com/v4/linode/instances/999/ips' => Http::response([
                'ipv4' => [
                    'public' => [
                        ['address' => '203.0.113.10', 'region' => 'us-east'],
                        ['address' => '203.0.113.11', 'region' => 'us-east'],
                    ],
                ],
            ]),
        ]);

        $reserved = $this->client()->listReservedIps(999);

        $this->assertCount(1, $reserved);
        $this->assertSame('203.0.113.11', $reserved[0]['ip']);
    }

    public function test_allocate_reserved_ip_requires_a_droplet_id(): void
    {
        $this->expectException(ProviderException::class);

        $this->client()->allocateReservedIp('us-east');
    }
}
