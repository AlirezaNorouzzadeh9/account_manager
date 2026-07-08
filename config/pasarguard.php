<?php

return [
    // Fixed shared PasarGuard node API key, used by every node this bot creates.
    // The TLS cert/key is NOT fixed — each node generates its own, since the
    // panel validates the node's certificate against the connecting IP.
    'api_key' => env('PASARGUARD_API_KEY'),

    // Optional. Credentials for the PasarGuard PANEL's own admin API (not a
    // node) — used only to force a node to reconnect after a domain-backed
    // node's IP changes underneath it (see PasarguardPanelClient,
    // WireguardProfile::core_id). Leave empty to fall back to just asking
    // the admin to reset the node manually.
    'panel' => [
        'url' => env('PASARGUARD_PANEL_URL'),
        'username' => env('PASARGUARD_PANEL_USERNAME'),
        'password' => env('PASARGUARD_PANEL_PASSWORD'),
    ],
];
