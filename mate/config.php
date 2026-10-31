<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        // Mate refuses to start under another PHP major.minor, whose report would not match the app's runtime.
        ->set('mate.php_version', '8.5')
        ->set('mate.invocation', 'vendor/bin/mate');
};
