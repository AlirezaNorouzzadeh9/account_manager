<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A server attached via "🔌 اتصال به سرور موجود" (ConnectServerConversation)
 * — never provisioned through a provider integration, so its SSH
 * credentials are stored here (keyed by host) instead of ServerSecret.
 * Lets a future automated re-push (e.g. after a WireguardLocation IP heals)
 * find and reconnect to it without asking the admin to re-type credentials.
 */
class ConnectedServer extends Model
{
    protected $fillable = [
        'host',
        'username',
        'password',
        'wireguard_profile_id',
        'created_by',
    ];

    protected $casts = [
        'password' => 'encrypted',
    ];

    public function wireguardProfile()
    {
        return $this->belongsTo(WireguardProfile::class);
    }

    /** Every connected server belongs to exactly one Telegram user — no sharing between users. */
    public function scopeOwnedBy($query, int|string $telegramId)
    {
        return $query->where('created_by', $telegramId);
    }
}
