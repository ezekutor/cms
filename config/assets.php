<?php

return [
    'minify' => false,
    'autoprefix' => false,
    'remote_asset_timeout' => 5,
    // Security: hard limit for downloaded remote assets (bytes).
    'remote_asset_max_bytes' => 2_097_152,
    // Security: optional whitelist for remote asset hosts.
    // Example: ['cdn.jsdelivr.net', 'cdnjs.cloudflare.com']
    'allowed_remote_hosts' => [],
];
