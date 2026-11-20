<?php

declare(strict_types=1);

use Intakt\Plugin\GitHubForge\Contract\GitHubForgeServices;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// The rest of github.client is in framework.yaml; its base URI is set here because YAML can name the parameter only by spelling it.
return static function (ContainerConfigurator $container): void {
    $container->extension('framework', ['http_client' => ['scoped_clients' => ['github.client' => ['base_uri' => '%'.GitHubForgeServices::API_URL.'%']]]]);
};
