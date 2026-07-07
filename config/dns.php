<?php

return [
    // Optional: when set, every new node gets a DNS A record created under
    // this domain (e.g. "srv-1.node.pcbot.top") pointing at its own IP, and
    // is given ONE fixed self-signed wildcard certificate instead of a
    // per-node one — since the panel then connects by that domain name
    // (covered by the wildcard SAN) rather than by the node's raw IP.
    // Leave CLOUDFLARE_API_TOKEN/ZONE_ID empty to keep the old per-node,
    // IP-based certificate behavior.
    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'node_domain' => env('CLOUDFLARE_NODE_DOMAIN', 'node.pcbot.top'),
    ],
];
