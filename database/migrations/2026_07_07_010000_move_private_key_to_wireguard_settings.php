<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PrivateKey identifies the client (this bot's WireGuard identity), not a
 * specific location — the same value is reused across every location's
 * generated config, so it belongs on the shared WireguardSettings singleton
 * instead of being repeated on each WireguardLocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wireguard_locations', function (Blueprint $table) {
            $table->dropColumn('private_key');
        });

        Schema::table('wireguard_settings', function (Blueprint $table) {
            $table->text('private_key')->nullable()->after('port');
        });
    }

    public function down(): void
    {
        Schema::table('wireguard_settings', function (Blueprint $table) {
            $table->dropColumn('private_key');
        });

        Schema::table('wireguard_locations', function (Blueprint $table) {
            $table->text('private_key')->nullable()->after('server_public_key');
        });
    }
};
