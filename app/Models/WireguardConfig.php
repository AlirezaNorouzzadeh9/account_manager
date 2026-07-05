<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WireguardConfig extends Model
{
    protected $fillable = [
        'name',
        'config',
    ];

    protected $casts = [
        'config' => 'encrypted',
    ];
}
