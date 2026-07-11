<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Telegram user granted access to the bot beyond the fixed
 * ADMIN_TELEGRAM_IDS env list ("owners" — see isOwner()). Every allowed
 * user, owner or not, gets their own fully isolated panels/servers/WireGuard
 * data; only owners can additionally manage this list (see
 * UserManagementMenu).
 */
class BotUser extends Model
{
    protected $fillable = [
        'telegram_id',
        'label',
        'added_by',
    ];

    /**
     * Owners are defined by the ADMIN_TELEGRAM_IDS env var, not this table —
     * they're the only ones who can grant/revoke access via the bot.
     */
    public static function isOwner(int|string|null $telegramId): bool
    {
        return $telegramId !== null && in_array((string) $telegramId, config('bot.admins'), true);
    }

    /** Owner OR a granted regular user — i.e. allowed to use the bot at all. */
    public static function isAllowed(int|string|null $telegramId): bool
    {
        if ($telegramId === null) {
            return false;
        }

        return self::isOwner($telegramId) || self::where('telegram_id', $telegramId)->exists();
    }
}
