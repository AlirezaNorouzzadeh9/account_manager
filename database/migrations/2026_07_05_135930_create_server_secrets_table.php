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
        Schema::create('server_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('panel_id')->constrained()->cascadeOnDelete();
            $table->string('provider_server_id');
            $table->text('root_password');
            $table->timestamps();

            $table->unique(['panel_id', 'provider_server_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_secrets');
    }
};
