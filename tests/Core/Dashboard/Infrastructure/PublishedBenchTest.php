<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\BenchHeadline;
use Intakt\Core\Dashboard\Infrastructure\PublishedBench;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishedBench::class)]
#[CoversClass(BenchHeadline::class)]
final class PublishedBenchTest extends TestCase
{
    public function testTheReadmeTableGivesThePanelsResultAndTheDateOfItsRun(): void
    {
        $file = $this->readme("| Reviewer | Defects found (measured) | False alarms (measured) | Tokens |\n| --- | --- | --- | --- |\n| Panel (`correctness` and `security`) | 11 of 15 | 2 | about 6,800 |\n| `security` alone | 8 of 15 | 0 | about 4,350 |\n\nDate of the run: 2026-11-16. Models: a, b.\n");

        $headline = new PublishedBench($file)->headline();

        self::assertNotNull($headline);
        self::assertSame(['11 of 15', 2, '2026-11-16'], [$headline->reading(), $headline->falseAlarms, $headline->ranOn]);
    }

    public function testAReadmeWithoutTheTableGivesNoHeadline(): void
    {
        self::assertNull(new PublishedBench($this->readme("# Intakt\n"))->headline());
        self::assertNull(new PublishedBench(sys_get_temp_dir().'/no-such-readme.md')->headline());
    }

    public function testTheShippedReadmeStillCarriesTheTable(): void
    {
        self::assertNotNull(new PublishedBench(__DIR__.'/../../../../README.md')->headline());
    }

    private function readme(string $text): string
    {
        $file = tempnam(sys_get_temp_dir(), 'readme');
        self::assertIsString($file);
        file_put_contents($file, $text);

        return $file;
    }
}
