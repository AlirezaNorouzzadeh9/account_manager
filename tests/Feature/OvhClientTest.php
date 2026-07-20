<?php

namespace Tests\Feature;

use App\Services\Providers\Ovh\OvhClient;
use App\Services\Providers\ProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OvhClientTest extends TestCase
{
    use RefreshDatabase;

    protected function client(): OvhClient
    {
        return new OvhClient('fake-consumer-key', 'fake-app-key', 'fake-app-secret', 'project-1');
    }

    protected function fakeTime(int $timestamp = 1700000000): void
    {
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response((string) $timestamp),
        ]);
    }

    public function test_account_returns_the_project_description_and_service_name(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1' => Http::response([
                'description' => 'My Project',
                'project_id' => 'project-1',
            ]),
        ]);

        $account = $this->client()->account();

        $this->assertSame('My Project', $account['email']);
        $this->assertSame('project-1', $account['uuid']);
    }

    public function test_account_throws_on_bad_credentials(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1' => Http::response([
                'message' => 'This credential is not valid',
            ], 403),
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('This credential is not valid');

        $this->client()->account();
    }

    public function test_the_signature_header_matches_ovhs_documented_algorithm(): void
    {
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1' => Http::response(['description' => 'p']),
        ]);

        $this->client()->account();

        $expected = '$1$'.sha1('fake-app-secret+fake-consumer-key+GET+https://eu.api.ovh.com/1.0/cloud/project/project-1++1700000000');

        Http::assertSent(function ($request) use ($expected) {
            if (! str_contains((string) $request->url(), '/cloud/project/project-1') || str_contains((string) $request->url(), '/region')) {
                return false;
            }

            return $request->hasHeader('X-Ovh-Application', 'fake-app-key')
                && $request->hasHeader('X-Ovh-Consumer', 'fake-consumer-key')
                && $request->hasHeader('X-Ovh-Timestamp', '1700000000')
                && $request->hasHeader('X-Ovh-Signature', $expected);
        });
    }

    public function test_bodyless_requests_sign_against_an_empty_string_and_send_no_body(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1/instance/srv-1/start' => Http::response([]),
        ]);

        $this->client()->powerOn('srv-1');

        $expected = '$1$'.sha1('fake-app-secret+fake-consumer-key+POST+https://eu.api.ovh.com/1.0/cloud/project/project-1/instance/srv-1/start++1700000000');

        Http::assertSent(function ($request) use ($expected) {
            if (! str_contains((string) $request->url(), '/start')) {
                return false;
            }

            return $request->body() === '' && $request->hasHeader('X-Ovh-Signature', $expected);
        });
    }

    public function test_regions_are_flagged_and_sorted_by_label(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1/region' => Http::response(['GRA11', 'BHS5', 'SYD1']),
        ]);

        $regions = $this->client()->regions();

        // sorted by label (flag first), not by slug: 🇦🇺 < 🇨🇦 < 🇫🇷
        $this->assertSame(['SYD1', 'BHS5', 'GRA11'], collect($regions)->pluck('slug')->all());

        $bySlug = collect($regions)->keyBy('slug');
        $this->assertStringContainsString('🇫🇷', $bySlug['GRA11']['label']);
        $this->assertStringContainsString('🇨🇦', $bySlug['BHS5']['label']);
        $this->assertStringContainsString('🇦🇺', $bySlug['SYD1']['label']);
    }

    public function test_sizes_filters_unavailable_flavors_and_attaches_catalog_pricing(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1/flavor*' => Http::response([
                ['id' => 'b2-7', 'vcpus' => 2, 'ram' => 7, 'disk' => 50, 'available' => true, 'planCodes' => ['monthly' => 'b2-7.monthly.consumption']],
                ['id' => 'b2-15', 'vcpus' => 4, 'ram' => 15, 'disk' => 100, 'available' => false, 'planCodes' => ['monthly' => 'b2-15.monthly.consumption']],
            ]),
            'eu.api.ovh.com/1.0/order/catalog/public/cloud*' => Http::response([
                'addons' => [
                    ['planCode' => 'b2-7.monthly.consumption', 'pricings' => [['price' => 3500000000]]],
                ],
            ]),
        ]);

        $sizes = $this->client()->sizes('GRA11');

        $this->assertCount(1, $sizes);
        $this->assertSame('b2-7', $sizes[0]['slug']);
        $this->assertSame(2, $sizes[0]['vcpus']);
        $this->assertSame(7168, $sizes[0]['memory']);
        $this->assertSame(35.0, $sizes[0]['price_monthly']);
    }

    public function test_images_returns_only_ubuntu_linux_images(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1/image*' => Http::response([
                ['id' => 'img-1', 'name' => 'Ubuntu 22.04'],
                ['id' => 'img-2', 'name' => 'Debian 12'],
            ]),
        ]);

        $images = $this->client()->images('distribution');

        $this->assertCount(1, $images);
        $this->assertSame('img-1', $images[0]['slug']);
        $this->assertStringContainsString('Ubuntu', $images[0]['label']);
    }

    public function test_create_server_sends_the_flavor_image_region_and_cloud_init_user_data(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1/instance' => Http::response([
                'id' => 'srv-1',
                'name' => 'my-server',
                'status' => 'BUILD',
                'region' => 'GRA11',
                'flavorId' => 'b2-7',
                'imageId' => 'img-1',
                'ipAddresses' => [],
            ]),
        ]);

        $result = $this->client()->createServer([
            'name' => 'my-server',
            'region' => 'GRA11',
            'size' => 'b2-7',
            'image' => 'img-1',
            'user_data' => "#cloud-config\nchpasswd:\n  expire: false\n  list: |\n    root:secret\nssh_pwauth: true\n",
        ]);

        $this->assertSame('srv-1', $result['droplet']['id']);
        $this->assertSame('srv-1', $result['links']['actions'][0]['id']);

        Http::assertSent(function ($request) {
            if (! str_contains((string) $request->url(), '/cloud/project/project-1/instance') || $request->method() !== 'POST') {
                return false;
            }

            $body = $request->data();

            return $body['flavorId'] === 'b2-7'
                && $body['imageId'] === 'img-1'
                && $body['region'] === 'GRA11'
                && str_contains($body['userData'], 'chpasswd');
        });
    }

    public function test_list_servers_normalizes_status_and_paginates_locally(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1/instance' => Http::response([
                ['id' => 'srv-1', 'name' => 'a', 'status' => 'ACTIVE', 'region' => 'GRA11', 'flavorId' => 'b2-7', 'imageId' => 'img-1', 'ipAddresses' => [['version' => 4, 'type' => 'public', 'ip' => '1.2.3.4']]],
                ['id' => 'srv-2', 'name' => 'b', 'status' => 'SHUTOFF', 'region' => 'GRA11', 'flavorId' => 'b2-7', 'imageId' => 'img-1', 'ipAddresses' => []],
            ]),
        ]);

        $result = $this->client()->listServers(1, 1);

        $this->assertCount(1, $result['items']);
        $this->assertSame('active', $result['items'][0]['status']);
        $this->assertSame('1.2.3.4', $result['items'][0]['networks']['v4'][0]['ip_address']);
        $this->assertTrue($result['has_more']);
    }

    public function test_get_server_enriches_with_flavor_details_and_price(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1/instance/srv-1' => Http::response([
                'id' => 'srv-1', 'name' => 'a', 'status' => 'ACTIVE', 'region' => 'GRA11', 'flavorId' => 'b2-7', 'imageId' => 'img-1', 'ipAddresses' => [],
            ]),
            'eu.api.ovh.com/1.0/cloud/project/project-1/flavor/b2-7' => Http::response([
                'id' => 'b2-7', 'vcpus' => 2, 'ram' => 7, 'disk' => 50, 'planCodes' => ['monthly' => 'b2-7.monthly.consumption'],
            ]),
            'eu.api.ovh.com/1.0/order/catalog/public/cloud*' => Http::response([
                'addons' => [['planCode' => 'b2-7.monthly.consumption', 'pricings' => [['price' => 3500000000]]]],
            ]),
        ]);

        $server = $this->client()->getServer('srv-1');

        $this->assertSame('active', $server['status']);
        $this->assertSame(35.0, $server['size']['price_monthly']);
    }

    public function test_delete_server_sends_a_delete_request(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1/instance/srv-1' => Http::response([]),
        ]);

        $this->client()->deleteServer('srv-1');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains((string) $request->url(), '/instance/srv-1'));
    }

    public function test_power_actions_hit_the_right_endpoints(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1/instance/srv-1/start' => Http::response([]),
            'eu.api.ovh.com/1.0/cloud/project/project-1/instance/srv-1/stop' => Http::response([]),
            'eu.api.ovh.com/1.0/cloud/project/project-1/instance/srv-1/reboot' => Http::response([]),
        ]);

        $this->client()->powerOn('srv-1');
        $this->client()->powerOff('srv-1');
        $this->client()->reboot('srv-1');

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/start') && $request->method() === 'POST');
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/stop') && $request->method() === 'POST');
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/reboot') && $request->data()['type'] === 'soft');
    }

    public function test_resize_sends_the_new_flavor_id(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1/instance/srv-1/resize' => Http::response([]),
        ]);

        $this->client()->resize('srv-1', 'b2-15', false);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/resize')
            && $request->data()['flavorId'] === 'b2-15');
    }

    public function test_rebuild_is_not_supported(): void
    {
        $this->expectException(ProviderException::class);

        $this->client()->rebuild('srv-1', 'img-1');
    }

    public function test_get_action_translates_instance_status_into_action_status(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1/instance/srv-1' => Http::response([
                'id' => 'srv-1', 'status' => 'BUILD',
            ]),
        ]);

        $action = $this->client()->getAction('srv-1');

        $this->assertSame('in-progress', $action['status']);
        $this->assertSame('droplet', $action['resource_type']);
        $this->assertSame('srv-1', $action['resource_id']);
    }

    public function test_get_action_reports_error_for_a_genuinely_failed_instance(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1/instance/srv-1' => Http::response([
                'id' => 'srv-1', 'status' => 'ERROR',
            ]),
        ]);

        $action = $this->client()->getAction('srv-1');

        $this->assertSame('error', $action['status']);
    }

    public function test_get_action_reports_completed_for_an_active_instance(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1/instance/srv-1' => Http::response([
                'id' => 'srv-1', 'status' => 'ACTIVE',
            ]),
        ]);

        $action = $this->client()->getAction('srv-1');

        $this->assertSame('completed', $action['status']);
    }

    public function test_list_reserved_ips_requires_a_droplet_id(): void
    {
        $this->expectException(ProviderException::class);

        $this->client()->listReservedIps();
    }

    public function test_list_reserved_ips_resolves_the_instances_region_first(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1/instance/srv-1' => Http::response(['id' => 'srv-1', 'region' => 'GRA11']),
            'eu.api.ovh.com/1.0/cloud/project/project-1/region/GRA11/floatingip' => Http::response([
                ['ip' => '5.6.7.8', 'associatedEntity' => ['id' => 'srv-1']],
                ['ip' => '9.9.9.9', 'associatedEntity' => ['id' => 'srv-2']],
            ]),
        ]);

        $ips = $this->client()->listReservedIps('srv-1');

        $this->assertCount(1, $ips);
        $this->assertSame('5.6.7.8', $ips[0]['ip']);
    }

    public function test_allocate_reserved_ip_requires_a_droplet_id(): void
    {
        $this->expectException(ProviderException::class);

        $this->client()->allocateReservedIp('GRA11');
    }

    public function test_allocate_reserved_ip_creates_and_attaches_in_one_call(): void
    {
        $this->fakeTime();
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1/region/GRA11/instance/srv-1/floatingIp' => Http::response(['ip' => '5.6.7.8']),
        ]);

        $result = $this->client()->allocateReservedIp('GRA11', 'srv-1');

        $this->assertSame('5.6.7.8', $result['reserved_ip']['ip']);
    }

    public function test_assign_reserved_ip_is_a_noop(): void
    {
        $this->assertSame([], $this->client()->assignReservedIp('5.6.7.8', 'srv-1'));
    }

    public function test_unassign_and_release_reserved_ip_are_not_supported(): void
    {
        $client = $this->client();

        foreach (['unassignReservedIp', 'releaseReservedIp'] as $method) {
            try {
                $client->$method('5.6.7.8');
                $this->fail("{$method} was expected to throw a ProviderException");
            } catch (ProviderException) {
                $this->assertTrue(true);
            }
        }
    }
}
