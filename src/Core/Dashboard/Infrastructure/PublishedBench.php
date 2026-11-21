<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\BenchHeadline;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Read from the README's review bench table, the one place the published run is kept, so the tile changes with the next published run and never carries a copied figure.
final readonly class PublishedBench
{
    private const string PANEL_ROW = '/^\| Panel\b[^|]*\| (\d+) of (\d+) \| (\d+) \|/m';
    private const string RUN_DATE = '/^Date of the run: (\d{4}-\d{2}-\d{2})\./m';

    public function __construct(
        #[Autowire('%kernel.project_dir%/README.md')]
        private string $readme,
    ) {
    }

    // Null when the README or its table is missing or changed shape, so the page shows no bench tile rather than a wrong one.
    public function headline(): ?BenchHeadline
    {
        $text = is_file($this->readme) ? (string) file_get_contents($this->readme) : '';
        if (1 !== preg_match(self::PANEL_ROW, $text, $row) || 1 !== preg_match(self::RUN_DATE, $text, $date)) {
            return null;
        }

        return new BenchHeadline((int) $row[1], (int) $row[2], (int) $row[3], $date[1]);
    }
}
