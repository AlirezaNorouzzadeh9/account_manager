<?php

namespace Tests\Feature;

use App\Enums\Provider;
use App\Services\Providers\DigitalOcean\DigitalOceanClient;
use App\Services\Providers\Linode\LinodeClient;
use App\Services\Providers\ProviderManager;
use Tests\TestCase;

class ProviderManagerTest extends TestCase
{
    public function test_linode_is_marked_available(): void
    {
        $this->assertTrue(Provider::Linode->isAvailable());
    }

    public function test_vultr_is_still_marked_unavailable(): void
    {
        $this->assertFalse(Provider::Vultr->isAvailable());
    }

    public function test_provider_manager_resolves_a_linode_client(): void
    {
        $client = ProviderManager::make(Provider::Linode, 'fake-token');

        $this->assertInstanceOf(LinodeClient::class, $client);
    }

    public function test_provider_manager_resolves_a_digitalocean_client(): void
    {
        $client = ProviderManager::make(Provider::DigitalOcean, 'fake-token');

        $this->assertInstanceOf(DigitalOceanClient::class, $client);
    }
}
