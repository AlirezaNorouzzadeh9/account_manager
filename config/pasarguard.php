<?php

return [
    // Fixed shared PasarGuard node API key, used by every node this bot creates.
    // The TLS cert/key is NOT fixed — each node generates its own, since the
    // panel validates the node's certificate against the connecting IP.
    'api_key' => env('PASARGUARD_API_KEY'),
];
