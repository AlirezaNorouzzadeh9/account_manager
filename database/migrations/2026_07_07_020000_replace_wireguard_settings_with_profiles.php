<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Address/DNS/AllowedIPs/Port/Table turned out to never need to change, so
 * they become fixed code constants (see PasarguardNodeInstaller) instead of
 * an editable WireguardSettings singleton. PrivateKey moves to a proper
 * per-server "profile" instead: a server admin picks a profile (name + its
 * own PrivateKey) each time WireGuard is installed/updated on a server, so
 * different servers can present different client identities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('wireguard_settings');

        Schema::create('wireguard_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('private_key');
            $table->timestamps();
        });

        Schema::table('server_secrets', function (Blueprint $table) {
            $table->foreignId('wireguard_profile_id')->nullable()->after('root_password')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('server_secrets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wireguard_profile_id');
        });

        Schema::dropIfExists('wireguard_profiles');

        Schema::create('wireguard_settings', function (Blueprint $table) {
            $table->id();
            $table->string('address')->default('10.14.0.2/16');
            $table->string('dns')->nullable();
            $table->string('allowed_ips')->default('0.0.0.0/0');
            $table->unsignedInteger('port')->default(51820);
            $table->text('private_key')->nullable();
            $table->string('routing_table')->nullable();
            $table->timestamps();
        });
    }
};
