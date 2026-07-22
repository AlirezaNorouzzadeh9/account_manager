<?php

namespace App\Services\Wireguard;

use App\Jobs\ConnectServerWireguardsJob;
use App\Jobs\UpdateWireguardsJob;
use App\Models\ConnectedServer;
use App\Models\ServerSecret;
use App\Models\WireguardLocation;
use App\Services\Dns\DnsResolver;

/**
 * "Fix a bad location ip" logic used by CheckWireguardTunnelsJob — a
 * location's ip is often just one of several IPs a rotating "hostname"
 * subdomain hands out, so re-resolving it usually recovers a bad one
 * without any admin action.
 *
 * Deliberately only ever invoked from a LOCAL tunnel-liveness signal, never
 * from an Iran ping — an Iran-only ping failure doesn't mean the tunnel is
 * actually down. An earlier Iran-ping-based check (CheckWireguardLocationJob)
 * called this too and was removed after it swapped a location's ip based on
 * nothing but a flaky Iran ping.
 */
class LocationHealer
{
    public function __construct(protected DnsResolver $dns)
    {
    }

    /**
     * Re-resolves $location->hostname; if it yields an ip different from the
     * one currently stored, swaps it in and pushes the fix out to every
     * affected server. Returns the new ip on success, or null if it
     * couldn't heal (no hostname set, unresolved, or resolved to the same
     * bad ip) — the caller decides what "couldn't heal" means for alerting.
     */
    public function heal(WireguardLocation $location): ?string
    {
        if (! $location->hostname) {
            return null;
        }

        $newIp = $this->dns->resolve($location->hostname);

        if (! $newIp || $newIp === $location->ip) {
            return null;
        }

        $location->update(['ip' => $newIp, 'ping_alerted' => false]);

        $this->pushToAffectedServers($location);

        return $newIp;
    }

    /**
     * Every WireGuard-enabled server of this location's owner gets a fresh
     * config pushed — panel-provisioned ones via UpdateWireguardsJob (looked
     * up through ServerSecret/Panel), manually-connected ones via
     * ConnectServerWireguardsJob (looked up through ConnectedServer, which
     * only exists because ConnectServerConversation now persists its
     * credentials).
     */
    protected function pushToAffectedServers(WireguardLocation $location): void
    {
        $panelServers = ServerSecret::whereNotNull('wireguard_profile_id')
            ->whereHas('panel', fn ($query) => $query->where('created_by', $location->created_by))
            ->get();

        foreach ($panelServers as $secret) {
            UpdateWireguardsJob::dispatch($secret->panel_id, $secret->provider_server_id, $location->created_by);
        }

        $connectedServers = ConnectedServer::ownedBy($location->created_by)
            ->whereNotNull('wireguard_profile_id')
            ->get();

        foreach ($connectedServers as $connected) {
            if (! $connected->wireguardProfile) {
                continue;
            }

            ConnectServerWireguardsJob::dispatch(
                $connected->host,
                $connected->username,
                $connected->password,
                $connected->wireguardProfile->private_key,
                $location->created_by,
                $location->created_by,
            );
        }
    }
}
