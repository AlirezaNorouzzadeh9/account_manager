<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text label the admin types themselves (e.g. "Albania") purely so a
 * profile is recognizable in the bot's UI — unlike `name`, this has no
 * technical constraint (name doubles as a DNS label and Linux interface
 * source), so it can hold spaces/non-Latin text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wireguard_profiles', function (Blueprint $table) {
            $table->string('country')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('wireguard_profiles', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
};
