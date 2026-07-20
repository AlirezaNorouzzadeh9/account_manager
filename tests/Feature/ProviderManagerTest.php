<?php

namespace Tests\Feature;

use App\Enums\Provider;
use App\Services\Providers\Azure\AzureClient;
use App\Services\Providers\DigitalOcean\DigitalOceanClient;
use App\Services\Providers\Linode\LinodeClient;
use App\Services\Providers\Ovh\OvhClient;
use App\Services\Providers\ProviderManager;
use App\Services\Providers\Vultr\VultrClient;
use Tests\TestCase;

class ProviderManagerTest extends TestCase
{
    public function test_linode_is_marked_available(): void
    {
        $this->assertTrue(Provider::Linode->isAvailable());
    }

    public function test_azure_is_marked_available(): void
    {
        $this->assertTrue(Provider::Azure->isAvailable());
    }

    public function test_vultr_is_marked_available(): void
    {
        $this->assertTrue(Provider::Vultr->isAvailable());
    }

    public function test_provider_manager_resolves_a_linode_client(): void
    {
        $client = ProviderManager::make(Provider::Linode, 'fake-token');

        $this->assertInstanceOf(LinodeClient::class, $client);
    }

    public function test_provider_manager_resolves_a_vultr_client(): void
    {
        $client = ProviderManager::make(Provider::Vultr, 'fake-token');

        $this->assertInstanceOf(VultrClient::class, $client);
    }

    public function test_provider_manager_resolves_a_digitalocean_client(): void
    {
        $client = ProviderManager::make(Provider::DigitalOcean, 'fake-token');

        $this->assertInstanceOf(DigitalOceanClient::class, $client);
    }

    public function test_provider_manager_resolves_an_azure_client_with_its_extra_credentials(): void
    {
        $client = ProviderManager::make(Provider::Azure, 'fake-secret', [
            'tenant_id' => 'tenant-1',
            'client_id' => 'client-1',
            'subscription_id' => 'sub-1',
            'resource_group' => 'my-rg',
        ]);

        $this->assertInstanceOf(AzureClient::class, $client);
    }

    public function test_ovh_is_marked_available(): void
    {
        $this->assertTrue(Provider::Ovh->isAvailable());
    }

    public function test_provider_manager_resolves_an_ovh_client_with_its_extra_credentials(): void
    {
        $client = ProviderManager::make(Provider::Ovh, 'fake-consumer-key', [
            'application_key' => 'ak-1',
            'application_secret' => 'as-1',
            'service_name' => 'project-1',
        ]);

        $this->assertInstanceOf(OvhClient::class, $client);
    }
}
