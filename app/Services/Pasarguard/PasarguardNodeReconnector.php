<?php

namespace App\Services\Pasarguard;

use App\Models\WireguardProfile;
use Throwable;

/**
 * Decides what to tell the admin about a domain-backed node's PasarGuard
 * panel registration after its IP changed underneath it: automatically
 * reconnects it via the panel's own API when possible
 * (WireguardProfile::core_id + config/pasarguard.php panel credentials),
 * or falls back to asking the admin to reset it manually.
 */
class PasarguardNodeReconnector
{
    public function reminder(?string $domain, ?WireguardProfile $profile): string
    {
        if (! $domain) {
            return '';
        }

        if ($profile?->core_id && $this->panelConfigured()) {
            try {
                $this->client()->reconnectNode($profile->core_id);

                return '✅ نود به‌صورت خودکار در پنل PasarGuard ریست شد.';
            } catch (Throwable $e) {
                // Backtick-wrapped: this message is sent with parse_mode
                // Markdown, and a raw exception message can easily contain
                // an unescaped "_"/"*" that Telegram reads as a formatting
                // entity, breaking the whole send.
                return "⚠️ ریست خودکار نود در پنل PasarGuard ناموفق بود (`{$e->getMessage()}`)؛ یک‌بار دستی از پنل ریست کنید.";
            }
        }

        return 'چون آی‌پی پشت این دامنه عوض شده، یک‌بار دستی این نود را از پنل PasarGuard ریست/ری‌استارت کنید تا تغییر اعمال شود.';
    }

    protected function panelConfigured(): bool
    {
        return filled(config('pasarguard.panel.url'))
            && filled(config('pasarguard.panel.username'))
            && filled(config('pasarguard.panel.password'));
    }

    protected function client(): PasarguardPanelClient
    {
        return new PasarguardPanelClient(
            config('pasarguard.panel.url'),
            config('pasarguard.panel.username'),
            config('pasarguard.panel.password'),
        );
    }
}
