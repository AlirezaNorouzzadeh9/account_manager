<?php

namespace App\Console\Commands;

use App\Jobs\CheckServerPingJob;
use App\Models\Panel;
use App\Models\ServerSecret;
use App\Services\Providers\ProviderManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs every 10 minutes (see the server's crontab — no schedule:run loop is
 * set up for this project, so this command is invoked directly): for every
 * server this bot has a full build spec for, fetches its current IP and
 * queues a CheckServerPingJob. Best-effort — a panel/server/API hiccup just
 * skips that one server instead of failing the whole run.
 */
class CheckServerPingsCommand extends Command
{
    protected $signature = 'servers:check-pings';

    protected $description = 'Queue an Iran ping check for every managed server, alerting admins if any fails';

    public function handle(): int
    {
        $secrets = ServerSecret::query()
            ->whereNotNull('region')
            ->whereNotNull('size')
            ->whereNotNull('image')
            ->whereNotNull('hostname')
            ->get();

        foreach ($secrets as $secret) {
            $panel = Panel::find($secret->panel_id);

            if (! $panel || ! $panel->is_active) {
                continue;
            }

            try {
                $server = ProviderManager::forPanel($panel)->getServer($secret->provider_server_id);
                $ip = collect($server['networks']['v4'] ?? [])->firstWhere('type', 'public')['ip_address'] ?? null;
            } catch (Throwable) {
                continue;
            }

            if (! $ip) {
                continue;
            }

            CheckServerPingJob::dispatch($secret->panel_id, (string) $secret->provider_server_id, $ip, $secret->hostname);
        }

        return self::SUCCESS;
    }
}
