<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wireguard_profiles', function (Blueprint $table) {
            $table->boolean('ping_alerted')->default(false)->after('core_id');
        });
    }

    public function down(): void
    {
        Schema::table('wireguard_profiles', function (Blueprint $table) {
            $table->dropColumn('ping_alerted');
        });
    }
};
