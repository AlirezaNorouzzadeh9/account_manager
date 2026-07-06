<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wireguard_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('wireguard_configs', function (Blueprint $table) {
            $table->foreignId('wireguard_profile_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('server_secrets', function (Blueprint $table) {
            $table->foreignId('wireguard_profile_id')->nullable()->after('root_password')
                ->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_secrets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wireguard_profile_id');
        });

        Schema::table('wireguard_configs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wireguard_profile_id');
        });

        Schema::dropIfExists('wireguard_profiles');
    }
};
