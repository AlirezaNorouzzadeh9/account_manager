<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerSecret extends Model
{
    protected $fillable = [
        'panel_id',
        'provider_server_id',
        'root_password',
    ];

    protected $casts = [
        'root_password' => 'encrypted',
    ];

    public function panel()
    {
        return $this->belongsTo(Panel::class);
    }
}
