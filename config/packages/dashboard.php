<?php

declare(strict_types=1);

use Intakt\Core\Dashboard\Contract\DashboardServices;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Read when the container is built, as the project file is in ai.php, because the sample project the start needs lives in the compiled container too, and a server started before the variable was set would never see it.
return static function (ContainerConfigurator $container): void {
    $repository = $_ENV['INTAKT_SAMPLE_REPOSITORY'] ?? $_SERVER['INTAKT_SAMPLE_REPOSITORY'] ?? getenv('INTAKT_SAMPLE_REPOSITORY');

    $container->parameters()->set(DashboardServices::SAMPLE_REPOSITORY, is_string($repository) && '' !== $repository ? $repository : null);
};
