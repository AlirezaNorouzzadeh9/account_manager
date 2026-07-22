<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WireguardLocation extends Model
{
    protected $fillable = [
        'name',
        'country',
        'ip',
        'hostname',
        'ping_alerted',
        'server_public_key',
        'created_by',
    ];

    protected $casts = [
        'ping_alerted' => 'boolean',
    ];

    /** Every location belongs to exactly one Telegram user — no sharing between users. */
    public function scopeOwnedBy($query, int|string $telegramId)
    {
        return $query->where('created_by', $telegramId);
    }

    /**
     * Flag emoji derived from `country` (a 2-letter ISO code, e.g. "DE") —
     * each letter maps to a Unicode "regional indicator symbol", so this
     * works for any real country without a hardcoded table. Falls back to a
     * globe when `country` isn't set or isn't a valid 2-letter code (e.g. an
     * older free-text value entered before this validation existed).
     */
    public function flag(): string
    {
        if (! $this->country || ! preg_match('/^[A-Za-z]{2}$/', $this->country)) {
            return '🌐';
        }

        $code = strtoupper($this->country);

        return mb_chr(0x1F1E6 + (ord($code[0]) - 65)).mb_chr(0x1F1E6 + (ord($code[1]) - 65));
    }
}
