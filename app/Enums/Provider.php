<?php

namespace App\Enums;

enum Provider: string
{
    case DigitalOcean = 'digitalocean';
    case Vultr = 'vultr';
    case Linode = 'linode';

    public function label(): string
    {
        return match ($this) {
            self::DigitalOcean => 'DigitalOcean',
            self::Vultr => 'Vultr',
            self::Linode => 'Linode',
        };
    }

    /**
     * Whether this provider already has a working client implementation.
     */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::DigitalOcean => true,
            default => false,
        };
    }
}
