<?php

use Flute\Core\Modules\Skinchanger\Controllers\SkinchangerController;
use Flute\Core\Router\Contracts\RouterInterface;

$router->group(['prefix' => '/profile/skinchanger/', 'middleware' => 'auth'], static function (RouterInterface $group) {
    $group->get('loadout', [SkinchangerController::class, 'loadout']);
    $group->post('save', [SkinchangerController::class, 'save'])->middleware('csrf');
    $group->post('catalog/sync', [SkinchangerController::class, 'syncCatalog'])->middleware('csrf');
});

$router->get('/skinchanger/catalog', [SkinchangerController::class, 'catalog'])->middleware('throttle');
