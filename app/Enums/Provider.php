<?php

namespace App\Enums;

enum Provider: string
{
    case DigitalOcean = 'digitalocean';
    case Vultr = 'vultr';
    case Linode = 'linode';
    case Azure = 'azure';
    case Ovh = 'ovh';

    public function label(): string
    {
        return match ($this) {
            self::DigitalOcean => 'DigitalOcean',
            self::Vultr => 'Vultr',
            self::Linode => 'Linode',
            self::Azure => 'Azure',
            self::Ovh => 'OVH',
        };
    }

    /**
     * Whether this provider already has a working client implementation.
     */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::DigitalOcean, self::Linode, self::Vultr, self::Azure, self::Ovh => true,
            default => false,
        };
    }
}
