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
    ];

    protected $casts = [
        'private_key' => 'encrypted',
    ];
}
