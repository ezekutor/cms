<?php

return [
    // DB connection from config/database.php for ws_* tables.
    'database_connection' => 'default',

    // Key of linked social provider that stores SteamID64 in user socials.
    'steam_social_key' => 'steam',

    // Path (relative to project root) where normalized skin catalog is cached.
    'catalog_cache_path' => 'storage/app/skinchanger/catalog.json',

    // URL to JSON dataset (you can point to ByMykel/CS2 API export).
    'catalog_source_url' => '',

    'catalog_timeout' => 8,
    'catalog_max_bytes' => 15_000_000,
];
