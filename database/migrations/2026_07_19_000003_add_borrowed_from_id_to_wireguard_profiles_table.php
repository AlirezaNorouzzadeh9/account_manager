<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wireguard_profiles', function (Blueprint $table) {
            // Which sibling profile's IP this one's domain is currently
            // temporarily borrowing (see CheckWireguardProfileJob) — lets a
            // later check notice that the BORROWED server has also gone
            // down and re-failover to a different one, instead of getting
            // stuck once already alerted.
            $table->unsignedBigInteger('borrowed_from_id')->nullable()->after('own_ip');
        });
    }

    public function down(): void
    {
        Schema::table('wireguard_profiles', function (Blueprint $table) {
            $table->dropColumn('borrowed_from_id');
        });
    }
};
