<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wireguard_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('ip');
            $table->string('server_public_key');
            $table->text('private_key');
            $table->timestamps();
        });

        // Singleton row: values shared by every location's generated config
        // (only ip/server_public_key/private_key differ per location).
        Schema::create('wireguard_settings', function (Blueprint $table) {
            $table->id();
            $table->string('address')->default('10.0.0.2/24');
            $table->string('dns')->nullable();
            $table->string('allowed_ips')->default('0.0.0.0/0');
            $table->unsignedInteger('port')->default(51820);
            $table->string('routing_table')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wireguard_settings');
        Schema::dropIfExists('wireguard_locations');
    }
};
