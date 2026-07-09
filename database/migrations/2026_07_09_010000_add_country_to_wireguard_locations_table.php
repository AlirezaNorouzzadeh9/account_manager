<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text label the admin types themselves (e.g. "Albania") purely so a
 * location is recognizable in the bot's UI — unlike `name`, which also
 * becomes the server's WireGuard interface/config filename (see
 * PasarguardNodeInstaller::sanitizeInterfaceName()), so it stays short and
 * technical (e.g. "al") while `country` can hold the full readable name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wireguard_locations', function (Blueprint $table) {
            $table->string('country')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('wireguard_locations', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
};
