<?php

namespace Flute\Core\Modules\Skinchanger\Services;

use Flute\Core\Database\Entities\User;
use RuntimeException;

class SkinchangerService
{
    private const LOADOUT_TABLES = [
        'weapons' => 'ws_weapon_skins',
        'knives' => 'ws_knives',
        'gloves' => 'ws_gloves',
        'agents' => 'ws_agents',
        'pins' => 'ws_pins',
        'musickits' => 'ws_musickits',
    ];

    private const SAVE_TYPES = [
        'weapon' => 'ws_weapon_skins',
        'knife' => 'ws_knives',
        'glove' => 'ws_gloves',
        'agent' => 'ws_agents',
        'pin' => 'ws_pins',
        'musickit' => 'ws_musickits',
    ];

    public function resolveSteamId64(User $user): ?string
    {
        $socialKey = (string) config('skinchanger.steam_social_key', 'steam');
        $steamSocial = $user->getSocialNetwork($socialKey);

        if (!$steamSocial || !isset($steamSocial->value)) {
            return null;
        }

        $steamid64 = trim((string) $steamSocial->value);

        if (!preg_match('/^\d{17}$/', $steamid64)) {
            return null;
        }

        return $steamid64;
    }

    public function getLoadoutBySteamId(string $steamid64): array
    {
        $this->assertSteamId64($steamid64);

        $connection = $this->getConnectionName();
        $result = [];

        foreach (self::LOADOUT_TABLES as $key => $table) {
            $result[$key] = db($connection)
                ->select()
                ->from($table)
                ->where('steamid64', $steamid64)
                ->fetchAll();
        }

        $result['player'] = db($connection)
            ->select()
            ->from('ws_players')
            ->where('steamid64', $steamid64)
            ->fetchOne();

        return $result;
    }

    public function saveSelection(string $steamid64, string $type, array $payload): void
    {
        $this->assertSteamId64($steamid64);

        $table = self::SAVE_TYPES[$type] ?? null;
        if ($table === null) {
            throw new RuntimeException('Unknown skinchanger type');
        }

        $connection = $this->getConnectionName();

        $row = $this->buildRow($steamid64, $type, $payload);
        $where = $this->buildPrimaryKeyFilter($type, $row);

        $deleteQuery = db($connection)->delete($table);
        foreach ($where as $column => $value) {
            $deleteQuery->where($column, $value);
        }
        $deleteQuery->run();

        db($connection)->insert($table)->values($row)->run();
    }

    public function getCatalog(int $limit = 100, int $offset = 0, ?string $search = null): array
    {
        $path = $this->catalogPath();
        if (!is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        $items = $decoded;

        if (is_string($search) && $search !== '') {
            $needle = mb_strtolower($search);
            $items = array_values(array_filter($items, static function ($item) use ($needle) {
                if (!is_array($item)) {
                    return false;
                }

                $name = mb_strtolower((string) ($item['name'] ?? ''));
                $weapon = mb_strtolower((string) ($item['weapon'] ?? ''));

                return str_contains($name, $needle) || str_contains($weapon, $needle);
            }));
        }

        return array_slice($items, max(0, $offset), max(1, min(500, $limit)));
    }

    public function syncCatalogFromRemote(): int
    {
        $url = trim((string) config('skinchanger.catalog_source_url', ''));
        if ($url === '') {
            throw new RuntimeException('skinchanger.catalog_source_url is empty');
        }

        if (!preg_match('#^https://#i', $url)) {
            throw new RuntimeException('Only HTTPS catalog URLs are allowed');
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => (int) config('skinchanger.catalog_timeout', 8),
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'https' => [
                'timeout' => (int) config('skinchanger.catalog_timeout', 8),
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Failed to download remote catalog');
        }

        $maxBytes = (int) config('skinchanger.catalog_max_bytes', 15_000_000);
        if ($maxBytes > 0 && strlen($body) > $maxBytes) {
            throw new RuntimeException('Remote catalog payload too large');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Remote catalog is not a valid JSON array');
        }

        $normalized = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalized[] = [
                'name' => (string) ($item['name'] ?? ''),
                'weapon' => (string) ($item['weapon']['name'] ?? $item['weapon'] ?? ''),
                'paint_index' => (int) ($item['paint_index'] ?? $item['paintIndex'] ?? 0),
                'image' => (string) ($item['image'] ?? $item['imageUrl'] ?? ''),
                'rarity' => (string) ($item['rarity']['name'] ?? $item['rarity'] ?? ''),
            ];
        }

        $path = $this->catalogPath();
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create catalog directory');
        }

        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($path, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Failed to save catalog');
        }

        return count($normalized);
    }

    private function buildPrimaryKeyFilter(string $type, array $row): array
    {
        return match ($type) {
            'weapon' => [
                'steamid64' => $row['steamid64'],
                'team' => $row['team'],
                'weapon_defindex' => $row['weapon_defindex'],
            ],
            'knife', 'glove', 'agent' => [
                'steamid64' => $row['steamid64'],
                'team' => $row['team'],
            ],
            'pin', 'musickit' => [
                'steamid64' => $row['steamid64'],
            ],
            default => throw new RuntimeException('Unsupported save type'),
        };
    }

    private function buildRow(string $steamid64, string $type, array $payload): array
    {
        $team = $this->sanitizeTeam($payload['team'] ?? 0);
        $paint = max(0, (int) ($payload['paint_defindex'] ?? 0));
        $wear = $this->sanitizeWear((float) ($payload['wear'] ?? 0.0001));
        $seed = max(0, (int) ($payload['seed'] ?? 0));
        $stattrak = max(0, (int) ($payload['stattrak'] ?? 0));
        $enabled = !empty($payload['enabled']) ? 1 : 0;

        return match ($type) {
            'weapon' => [
                'steamid64' => $steamid64,
                'team' => $team,
                'weapon_defindex' => max(1, (int) ($payload['weapon_defindex'] ?? 0)),
                'paint_defindex' => $paint,
                'wear' => $wear,
                'seed' => $seed,
                'stattrak' => $stattrak,
                'enabled' => $enabled,
            ],
            'knife' => [
                'steamid64' => $steamid64,
                'team' => $team,
                'knife_defindex' => max(1, (int) ($payload['knife_defindex'] ?? 0)),
                'paint_defindex' => $paint,
                'wear' => $wear,
                'seed' => $seed,
                'stattrak' => $stattrak,
                'enabled' => $enabled,
            ],
            'glove' => [
                'steamid64' => $steamid64,
                'team' => $team,
                'glove_defindex' => max(1, (int) ($payload['glove_defindex'] ?? 0)),
                'paint_defindex' => $paint,
                'wear' => $wear,
                'seed' => $seed,
                'enabled' => $enabled,
            ],
            'agent' => [
                'steamid64' => $steamid64,
                'team' => $team,
                'agent_defindex' => max(1, (int) ($payload['agent_defindex'] ?? 0)),
                'enabled' => $enabled,
            ],
            'pin' => [
                'steamid64' => $steamid64,
                'pin_defindex' => max(1, (int) ($payload['pin_defindex'] ?? 0)),
                'enabled' => $enabled,
            ],
            'musickit' => [
                'steamid64' => $steamid64,
                'musickit_defindex' => max(1, (int) ($payload['musickit_defindex'] ?? 0)),
                'enabled' => $enabled,
            ],
            default => throw new RuntimeException('Unsupported save type'),
        };
    }

    private function sanitizeTeam(mixed $value): int
    {
        $team = (int) $value;

        return in_array($team, [0, 2, 3], true) ? $team : 0;
    }

    private function sanitizeWear(float $value): float
    {
        if ($value < 0.0001) {
            return 0.0001;
        }

        if ($value > 1) {
            return 1;
        }

        return $value;
    }

    private function assertSteamId64(string $steamid64): void
    {
        if (!preg_match('/^\d{17}$/', $steamid64)) {
            throw new RuntimeException('Invalid SteamID64');
        }
    }

    private function getConnectionName(): string
    {
        return (string) config('skinchanger.database_connection', 'default');
    }

    private function catalogPath(): string
    {
        $configuredPath = trim((string) config('skinchanger.catalog_cache_path', ''));

        return $configuredPath !== ''
            ? path($configuredPath)
            : path('storage/app/skinchanger/catalog.json');
    }
}
