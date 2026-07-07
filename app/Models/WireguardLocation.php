<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WireguardLocation extends Model
{
    protected $fillable = [
        'name',
        'ip',
        'server_public_key',
        'private_key',
    ];

    protected $casts = [
        'private_key' => 'encrypted',
    ];
}
