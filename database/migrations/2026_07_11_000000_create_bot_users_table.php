<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram users granted access to the bot BEYOND the fixed ADMIN_TELEGRAM_IDS
 * env list ("owners"). Owners can add/remove rows here; every such user gets
 * their own fully isolated panels/servers/WireGuard locations & profiles,
 * same as an owner does — the only extra owners have is managing this list
 * (see BotUser::isOwner()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_id')->unique();
            $table->string('label')->nullable();
            $table->unsignedBigInteger('added_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_users');
    }
};
