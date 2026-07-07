<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton row: the one fixed self-signed wildcard cert/key reused across
 * every node once DNS-based node addressing is configured. See
 * PasarguardNodeInstaller::ensureFixedCertificate().
 */
class NodeCertificate extends Model
{
    protected $fillable = [
        'certificate',
        'private_key',
    ];

    protected $casts = [
        'private_key' => 'encrypted',
    ];
}
