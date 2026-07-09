<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WireguardLocation extends Model
{
    protected $fillable = [
        'name',
        'country',
        'ip',
        'server_public_key',
    ];
}
