<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WireguardConfig extends Model
{
    protected $fillable = [
        'name',
        'config',
        'wireguard_profile_id',
    ];

    protected $casts = [
        'config' => 'encrypted',
    ];

    public function profile()
    {
        return $this->belongsTo(WireguardProfile::class, 'wireguard_profile_id');
    }
}
