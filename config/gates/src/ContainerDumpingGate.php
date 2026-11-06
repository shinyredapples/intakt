<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class ContainerDumpingGate implements Gate
{
    // Because a debug kernel dumps its compiled container as XML beside the compiled class, where phpstan-symfony reads the service ids and parameter types.
    private const string CONTAINER_XML = '/test/Intakt_KernelTestDebugContainer.xml';

    public function __construct(private Gate $analysis)
    {
    }

    // Because the kernel builds in APP_CACHE_DIR when it is set, as each test worker does, the dump is read from where it was written.
    public static function containerXml(string $home): string
    {
        $cache = getenv('APP_CACHE_DIR');

        return (is_string($cache) && '' !== $cache ? $cache : $home.'/var/cache').self::CONTAINER_XML;
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
        // Because a fresh cache is not compiled again, a dump that is gone comes back only when the cache is cleared.
        $command = is_file(self::containerXml(dirname($workspace->console(), 2))) ? 'cache:warmup' : 'cache:clear';
        // Because the caller's debug or verbosity setting would skip the XML dump or silence the reason.
        $environment = ['APP_DEBUG' => '1', 'SHELL_VERBOSITY' => false];

        return $workspace->command([PHP_BINARY, $workspace->console(), $command, '--env=test', '--no-optional-warmers', '--no-ansi', '--no-interaction'], $environment);
    }
}
