<?php

namespace App\Services\Providers;

/**
 * Common contract every cloud provider client must implement so the bot's
 * conversations/menus can work with any datacenter the same way.
 */
interface ProviderClient
{
    /**
     * Validate the stored token and return basic account info (email, uuid, droplet limit...).
     *
     * @throws ProviderException
     */
    public function account(): array;

    /** @return array<int, array> list of regions/datacenters */
    public function regions(): array;

    /** @return array<int, array> list of sizes/plans, optionally filtered by region slug */
    public function sizes(?string $region = null): array;

    /** @return array<int, array> list of OS images, $type is distribution|application|private */
    public function images(string $type = 'distribution'): array;

    public function createServer(array $data): array;

    /** @return array{items: array<int, array>, has_more: bool} */
    public function listServers(int $page = 1, int $perPage = 20): array;

    public function getServer(int|string $id): array;

    public function deleteServer(int|string $id): void;

    public function powerOn(int|string $id): array;

    public function powerOff(int|string $id): array;

    public function reboot(int|string $id): array;

    public function resize(int|string $id, string $size, bool $resizeDisk): array;

    public function rebuild(int|string $id, string $image): array;

    /** @return array the action object, used for polling until status !== "in-progress" */
    public function getAction(int|string $actionId): array;

    /** @return array<int, array> reserved IPs, optionally filtered by the droplet they're assigned to */
    public function listReservedIps(int|string|null $dropletId = null): array;

    public function allocateReservedIp(string $region): array;

    public function assignReservedIp(string $ip, int|string $dropletId): array;

    public function unassignReservedIp(string $ip): array;

    public function releaseReservedIp(string $ip): void;
}
