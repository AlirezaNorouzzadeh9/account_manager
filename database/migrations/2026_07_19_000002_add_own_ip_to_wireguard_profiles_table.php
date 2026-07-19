<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wireguard_profiles', function (Blueprint $table) {
            // The profile's own real server IP — set whenever a node is
            // (re)installed for it, so the domain-centric health check knows
            // which IP is actually "home" and can restore the domain to it
            // once a temporary failover (borrowed sibling IP) recovers.
            $table->string('own_ip')->nullable()->after('ping_alerted');
        });
    }

    public function down(): void
    {
        Schema::table('wireguard_profiles', function (Blueprint $table) {
            $table->dropColumn('own_ip');
        });
    }
};
