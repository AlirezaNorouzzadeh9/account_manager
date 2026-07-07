<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton row: the [Interface]/[Peer] fields shared by every WireGuard
 * location (only ip/server_public_key/private_key vary per location — see
 * WireguardLocation).
 */
class WireguardSettings extends Model
{
    protected $fillable = [
        'address',
        'dns',
        'allowed_ips',
        'port',
        'routing_table',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate();
    }
}
