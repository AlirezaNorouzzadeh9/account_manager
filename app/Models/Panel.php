<?php

namespace App\Models;

use App\Enums\Provider;
use Illuminate\Database\Eloquent\Model;

class Panel extends Model
{
    protected $fillable = [
        'name',
        'provider',
        'api_token',
        'meta',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'provider' => Provider::class,
        'api_token' => 'encrypted',
        'meta' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Every panel belongs to exactly one Telegram user — no sharing between users. */
    public function scopeOwnedBy($query, int|string $telegramId)
    {
        return $query->where('created_by', $telegramId);
    }
}
