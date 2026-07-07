<?php

namespace Tests\Feature;

use App\Services\Dns\CloudflareDnsClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CloudflareDnsClientTest extends TestCase
{
    public function test_creates_a_record_when_none_exists(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'rec-1']]),
        ]);

        (new CloudflareDnsClient('token-1', 'zone-1'))->upsertARecord('srv-1.node.pcbot.top', '1.2.3.4');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains((string) $request->url(), '/dns_records')
            && ($request['type'] ?? null) === 'A'
            && ($request['name'] ?? null) === 'srv-1.node.pcbot.top'
            && ($request['content'] ?? null) === '1.2.3.4');
    }

    public function test_updates_existing_record_instead_of_creating_a_duplicate(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response([
                'success' => true,
                'result' => [['id' => 'rec-existing']],
            ]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records/rec-existing' => Http::response(['success' => true, 'result' => ['id' => 'rec-existing']]),
        ]);

        (new CloudflareDnsClient('token-1', 'zone-1'))->upsertARecord('srv-1.node.pcbot.top', '5.6.7.8');

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_contains((string) $request->url(), '/dns_records/rec-existing')
            && ($request['content'] ?? null) === '5.6.7.8');
    }

    public function test_throws_with_cloudflares_error_message_on_failure(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Invalid zone']],
            ], 400),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid zone');

        (new CloudflareDnsClient('token-1', 'zone-1'))->upsertARecord('srv-1.node.pcbot.top', '1.2.3.4');
    }
}
