<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton row caching the ONE fixed self-signed wildcard certificate used
 * for every node once DNS-based node addressing is configured (see
 * config/dns.php) — generated lazily on first use so it only needs
 * generating once, not per node.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_certificates', function (Blueprint $table) {
            $table->id();
            $table->text('certificate');
            $table->text('private_key');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_certificates');
    }
};
