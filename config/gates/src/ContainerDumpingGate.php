<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class ContainerDumpingGate implements Gate
{
    // Because a debug kernel dumps its compiled container as XML, where phpstan-symfony reads the service ids and parameter types.
    public const string CONTAINER_XML = 'var/cache/test/Intakt_KernelTestDebugContainer.xml';

    public function __construct(private Gate $analysis)
    {
    }

    public function name(): string
    {
        return $this->analysis->name();
    }

    public function run(Workspace $workspace): GateResult
    {
        $dump = $this->dumpTestContainer($workspace);

        if (!$dump->succeeded()) {
            return GateResult::failedBecause($this->name(), 'the test container could not be dumped: '.$dump->firstErrorLine());
        }

        return $this->analysis->run($workspace);
    }

    private function dumpTestContainer(Workspace $workspace): ProcessResult
    {
        // Because the caller's debug or verbosity setting would skip the XML dump or silence the reason.
        $environment = ['APP_DEBUG' => '1', 'SHELL_VERBOSITY' => false];

        return $workspace->command([PHP_BINARY, $workspace->console(), 'cache:warmup', '--env=test', '--no-optional-warmers', '--no-ansi', '--no-interaction'], $environment);
    }
}
