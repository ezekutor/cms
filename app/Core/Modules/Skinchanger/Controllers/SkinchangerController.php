<?php

namespace Flute\Core\Modules\Skinchanger\Controllers;

use Flute\Core\Modules\Skinchanger\Services\SkinchangerService;
use Flute\Core\Support\FluteRequest;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;

class SkinchangerController
{
    public function __construct(private SkinchangerService $skinchangerService)
    {
    }

    public function loadout(): JsonResponse
    {
        $currentUser = user()->getCurrentUser();

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $steamid64 = $this->skinchangerService->resolveSteamId64($currentUser);
        if ($steamid64 === null) {
            return response()->json(['error' => 'Steam account is not linked'], 400);
        }

        return response()->json([
            'steamid64' => $steamid64,
            'loadout' => $this->skinchangerService->getLoadoutBySteamId($steamid64),
        ]);
    }

    public function save(FluteRequest $request): JsonResponse
    {
        $currentUser = user()->getCurrentUser();

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $steamid64 = $this->skinchangerService->resolveSteamId64($currentUser);
        if ($steamid64 === null) {
            return response()->json(['error' => 'Steam account is not linked'], 400);
        }

        $type = trim((string) $request->input('type', ''));
        $payload = $request->input('data', []);

        if (!is_array($payload)) {
            return response()->json(['error' => 'Invalid payload'], 422);
        }

        try {
            $this->skinchangerService->saveSelection($steamid64, $type, $payload);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function catalog(FluteRequest $request): JsonResponse
    {
        $limit = (int) $request->input('limit', 100);
        $offset = (int) $request->input('offset', 0);
        $search = trim((string) $request->input('search', ''));

        return response()->json([
            'items' => $this->skinchangerService->getCatalog($limit, $offset, $search === '' ? null : $search),
        ]);
    }

    public function syncCatalog(): JsonResponse
    {
        if (!user()->can('admin.boss')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        try {
            $count = $this->skinchangerService->syncCatalogFromRemote();
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'synced' => $count,
        ]);
    }
}
