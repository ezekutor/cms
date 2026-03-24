<?php

namespace Flute\Core\Modules\Skinchanger\Providers;

use Flute\Core\Support\AbstractServiceProvider;

class SkinchangerServiceProvider extends AbstractServiceProvider
{
    public function register(\DI\ContainerBuilder $containerBuilder): void
    {
    }

    public function boot(\DI\Container $container): void
    {
        if (is_installed()) {
            $this->loadRoutesFrom(cms_path('Skinchanger/Routes/skinchanger.php'));
        }
    }
}
