<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WireGuard profiles/configs are replaced by a flat "locations" model (see
 * the following migration) — every server that has WireGuard enabled now
 * gets ALL saved locations, so there's no more per-server profile choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_secrets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wireguard_profile_id');
        });

        Schema::dropIfExists('wireguard_configs');
        Schema::dropIfExists('wireguard_profiles');
    }

    public function down(): void
    {
        Schema::create('wireguard_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('wireguard_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wireguard_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('config');
            $table->timestamps();
        });

        Schema::table('server_secrets', function (Blueprint $table) {
            $table->foreignId('wireguard_profile_id')->nullable()->after('root_password')
                ->constrained()->nullOnDelete();
        });
    }
};
