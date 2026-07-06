<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WireguardProfile extends Model
{
    protected $fillable = [
        'name',
    ];

    public function configs()
    {
        return $this->hasMany(WireguardConfig::class);
    }
}
