<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Servers attached via "🔌 اتصال به سرور موجود" (ConnectServerConversation)
 * were never persisted anywhere — every re-run needed the admin to re-type
 * host/username/password by hand, and there was no record for a future
 * automated re-push (e.g. after a WireguardLocation IP heals) to find. This
 * mirrors server_secrets' per-server credential storage, just keyed by host
 * instead of a panel_id/provider_server_id pair since these servers were
 * never provisioned through a provider integration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_servers', function (Blueprint $table) {
            $table->id();
            $table->string('host');
            $table->string('username');
            $table->text('password');
            $table->foreignId('wireguard_profile_id')->nullable()->constrained('wireguard_profiles')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['host', 'created_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_servers');
    }
};
