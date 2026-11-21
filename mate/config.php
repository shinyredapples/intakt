<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        // Mate refuses to start under another PHP major.minor, whose report would not match the app's runtime.
        ->set('mate.php_version', '8.5')
        ->set('mate.invocation', 'vendor/bin/mate');

    // bin/instance moves the app's cache to its scratch folder through APP_CACHE_DIR, so the profiles a running instance saves are only found there.
    $appCache = getenv('APP_CACHE_DIR');

    if (is_string($appCache) && '' !== $appCache) {
        $container->parameters()->set('ai_mate_symfony.profiler_dir', str_replace('%', '%%', $appCache).'/dev/profiler');
    }
};
