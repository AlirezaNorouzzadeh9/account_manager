<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton row: the [Interface]/[Peer] fields shared by every WireGuard
 * location — including PrivateKey, which identifies this bot's WireGuard
 * client itself rather than any one location (only ip/server_public_key vary
 * per location — see WireguardLocation).
 */
class WireguardSettings extends Model
{
    protected $fillable = [
        'address',
        'dns',
        'allowed_ips',
        'port',
        'routing_table',
        'private_key',
    ];

    protected $casts = [
        'private_key' => 'encrypted',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate();
    }
}
