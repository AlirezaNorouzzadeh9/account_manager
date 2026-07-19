<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A per-server WireGuard identity: name + PrivateKey. Assigned to a server
 * (see ServerSecret::wireguardProfile) so different servers can present
 * different client identities to the same set of WireguardLocations.
 */
class WireguardProfile extends Model
{
    protected $fillable = [
        'name',
        'private_key',
        'core_id',
        'created_by',
        'ping_alerted',
    ];

    protected $casts = [
        'private_key' => 'encrypted',
        'core_id' => 'integer',
        'ping_alerted' => 'boolean',
    ];

    /** Every profile belongs to exactly one Telegram user — no sharing between users. */
    public function scopeOwnedBy($query, int|string $telegramId)
    {
        return $query->where('created_by', $telegramId);
    }
}
