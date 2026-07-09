<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reverts 2026_07_09_000000_add_country_to_wireguard_profiles_table — the
 * country label was actually wanted on WireguardLocation (see
 * 2026_07_09_010000_add_country_to_wireguard_locations_table), not the
 * per-server WireguardProfile identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wireguard_profiles', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }

    public function down(): void
    {
        Schema::table('wireguard_profiles', function (Blueprint $table) {
            $table->string('country')->nullable()->after('name');
        });
    }
};
