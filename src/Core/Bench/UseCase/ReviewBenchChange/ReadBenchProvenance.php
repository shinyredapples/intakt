<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\UseCase\ReviewBenchChange;

use Composer\InstalledVersions;
use Intakt\Core\Bench\Contract\BenchProvenance;
use Intakt\Core\Review\Contract\ReviewPrompts;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

final readonly class ReadBenchProvenance
{
    private const string HASH = 'sha256';

    public function __construct(
        private ReviewPrompts $prompts,
        #[Autowire('%kernel.project_dir%/fixtures/sample-repository')]
        private string $fixtures,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function read(): BenchProvenance
    {
        return new BenchProvenance($this->intaktVersion(), $this->prompts->hashes(), $this->benchRepositoryHash());
    }

    // The commit beside the version when Composer knows it, because a checkout of main is always the version dev-main.
    private function intaktVersion(): string
    {
        $root = InstalledVersions::getRootPackage();

        return null === $root['reference'] ? $root['pretty_version'] : $root['pretty_version'].'@'.$root['reference'];
    }

    // Over the base and every change, each file's path and content in path order, so a changed line, file or name changes the hash and the order of reading never does; hidden files too, because the checkout copies them into the reviewed repository.
    private function benchRepositoryHash(): string
    {
        $entries = [];
        foreach (new Finder()->files()->ignoreDotFiles(false)->in([$this->fixtures.'/base', $this->fixtures.'/bench'])->sortByName() as $file) {
            $entries[] = hash(self::HASH, substr($file->getPathname(), strlen($this->fixtures))."\0".$this->filesystem->readFile($file->getPathname()));
        }

        return hash(self::HASH, implode("\n", $entries));
    }
}
