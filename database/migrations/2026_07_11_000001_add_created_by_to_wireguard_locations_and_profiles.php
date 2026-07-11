<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WireGuard locations and profiles are now per-user (same as panels/servers
 * already were via panels.created_by) — each user manages their own separate
 * set instead of sharing one global list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wireguard_locations', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('id');
        });

        Schema::table('wireguard_profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('wireguard_locations', function (Blueprint $table) {
            $table->dropColumn('created_by');
        });

        Schema::table('wireguard_profiles', function (Blueprint $table) {
            $table->dropColumn('created_by');
        });
    }
};
