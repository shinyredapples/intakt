<?php

declare(strict_types=1);

use Intakt\Core\Ai\Infrastructure\ProjectsConfiguration;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Yaml\Yaml;

// Apart from the AI Bundle's config because projects are the owner's data per install, so the file stays out of git; a missing file means no projects rather than a failed boot.
return static function (ContainerConfigurator $container): void {
    $path = $_SERVER['INTAKT_CONFIG'] ?? $_ENV['INTAKT_CONFIG'] ?? getenv('INTAKT_CONFIG');
    $path = is_string($path) && '' !== $path ? $path : __DIR__.'/../intakt.yaml';
    $contents = is_file($path) ? Yaml::parseFile($path) : [];

    $config = new Processor()->processConfiguration(new ProjectsConfiguration(), [$contents ?? []]);

    $container->parameters()->set('intakt.projects', $config['projects']);
};
