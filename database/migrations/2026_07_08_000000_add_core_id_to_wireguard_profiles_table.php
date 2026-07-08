<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "core_id" is the PasarGuard PANEL's own node id (the `id` field on a node
 * object in its API, e.g. from GET /api/node/{id}) for whichever node this
 * profile's domain is registered as. Needed to call POST
 * /api/node/{core_id}/reconnect after a domain-backed IP change, since the
 * panel doesn't notice a DNS change on its own (see PasarguardPanelClient).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wireguard_profiles', function (Blueprint $table) {
            $table->unsignedInteger('core_id')->nullable()->after('private_key');
        });
    }

    public function down(): void
    {
        Schema::table('wireguard_profiles', function (Blueprint $table) {
            $table->dropColumn('core_id');
        });
    }
};
