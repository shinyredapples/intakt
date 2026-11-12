<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\GitHubForge\Domain\WorkflowFile;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

final readonly class WorkflowFiles
{
    private const string FOLDER = '.github/workflows';

    public function __construct(
        private Filesystem $filesystem,
    ) {
    }

    // GitHub runs only the files directly in the folder; any entry so named is listed, so one that cannot be read is reported.
    /** @return list<WorkflowFile> */
    public function in(string $checkout): array
    {
        $folder = $checkout.'/'.self::FOLDER;
        if (!is_dir($folder)) {
            return [];
        }
        $entries = Finder::create()->in($folder)->depth(0)->name(['*.yml', '*.yaml'])->ignoreDotFiles(false)->sortByName();

        return array_values(array_map($this->read(...), iterator_to_array($entries, false)));
    }

    private function read(SplFileInfo $entry): WorkflowFile
    {
        $path = self::FOLDER.'/'.$entry->getRelativePathname();
        try {
            return new WorkflowFile($path, $this->filesystem->readFile($entry->getPathname()));
        } catch (IOException) {
            return new WorkflowFile($path, null);
        }
    }
}
