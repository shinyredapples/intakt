<?php

declare(strict_types=1);

use Intakt\Core\Ai\Infrastructure\ProjectsConfiguration;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Yaml\Yaml;

// Intakt's own config, apart from the AI Bundle's: INTAKT_CONFIG names the file, else config/intakt.yaml; without a file there are no projects.
return static function (ContainerConfigurator $container): void {
    $path = $_SERVER['INTAKT_CONFIG'] ?? $_ENV['INTAKT_CONFIG'] ?? getenv('INTAKT_CONFIG');
    $path = is_string($path) && '' !== $path ? $path : __DIR__.'/../intakt.yaml';
    $contents = is_file($path) ? Yaml::parseFile($path) : [];

    $config = new Processor()->processConfiguration(new ProjectsConfiguration(), [$contents ?? []]);

    $container->parameters()->set('intakt.projects', $config['projects']);
};
