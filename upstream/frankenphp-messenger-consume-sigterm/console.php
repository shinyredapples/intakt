<?php

declare(strict_types=1);

namespace Intakt\Upstream\FrankenphpMessengerConsumeSigterm;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\MessageBusInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class Slow
{
}

// The handler is in flight between the "started" and the "done" marker; the test sends SIGTERM while it waits for "release".
final class SlowHandler
{
    public function __invoke(Slow $message): void
    {
        $directory = (string) getenv('PROBE_DIR');
        touch($directory.'/started');

        $deadline = hrtime(true) + 20_000_000_000;
        while (!is_file($directory.'/release') && hrtime(true) < $deadline) {
            usleep(10_000);
        }

        touch($directory.'/done');
    }
}

#[AsCommand(name: 'probe:dispatch')]
final class DispatchCommand extends Command
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bus->dispatch(new Slow());

        return Command::SUCCESS;
    }
}

final class ProbeKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new DoctrineBundle()];
    }

    public function getCacheDir(): string
    {
        return getenv('PROBE_DIR').'/cache';
    }

    public function getLogDir(): string
    {
        return getenv('PROBE_DIR').'/log';
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'probe',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'messenger' => [
                'transports' => ['async' => 'doctrine://default?auto_setup=true'],
                'routing' => [Slow::class => 'async'],
            ],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///'.getenv('PROBE_DIR').'/queue.sqlite'],
        ]);
        $container->services()
            ->defaults()->autowire()->autoconfigure()
            ->set(SlowHandler::class)->tag('messenger.message_handler')
            ->set(DispatchCommand::class);
    }
}

(new Application(new ProbeKernel('prod', false)))->run();
