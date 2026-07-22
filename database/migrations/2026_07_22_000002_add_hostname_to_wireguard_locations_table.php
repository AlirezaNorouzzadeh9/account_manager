<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A location's "ip" is often just one of several IPs a rotating subdomain
 * hands out — when THAT particular IP goes bad, the admin has to manually
 * notice and re-pick one. Optionally recording the subdomain here lets
 * CheckWireguardLocationJob re-resolve it and swap in a fresh IP on its own
 * (see wireguard:check-locations), mirroring the existing
 * WireguardProfile::ping_alerted pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wireguard_locations', function (Blueprint $table) {
            $table->string('hostname')->nullable()->after('ip');
            $table->boolean('ping_alerted')->default(false)->after('hostname');
        });
    }

    public function down(): void
    {
        Schema::table('wireguard_locations', function (Blueprint $table) {
            $table->dropColumn(['hostname', 'ping_alerted']);
        });
    }
};
