<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Bench\UseCase\ReviewBenchChange;

use Intakt\Core\Bench\Contract\BenchProvenance;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\ReadBenchProvenance;
use Intakt\Core\Review\Contract\PromptHashes;
use Intakt\Core\Review\Infrastructure\HashedReviewPrompts;
use Intakt\Core\Review\Infrastructure\JudgePrompt;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ReadBenchProvenance::class)]
#[CoversClass(BenchProvenance::class)]
#[UsesClass(HashedReviewPrompts::class)]
#[UsesClass(PromptHashes::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(JudgePrompt::class)]
final class ReadBenchProvenanceTest extends TestCase
{
    private string $fixtures;

    public function testTheProvenanceNamesTheVersionThePromptsAndTheBenchRepository(): void
    {
        $provenance = $this->read();

        self::assertNotSame('', $provenance->intaktVersion);
        self::assertSame(new HashedReviewPrompts()->hashes()->reviewer, $provenance->prompts->reviewer);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $provenance->benchRepositoryHash);
    }

    public function testTheBenchRepositoryHashIsStableAndChangesWithAFileOfTheBench(): void
    {
        $before = $this->read()->benchRepositoryHash;
        self::assertSame($before, $this->read()->benchRepositoryHash);

        new Filesystem()->dumpFile($this->fixtures.'/bench/change/tree/a.js', "three\n");

        self::assertNotSame($before, $this->read()->benchRepositoryHash);
    }

    public function testAChangedHiddenFileOfTheBenchChangesTheBenchRepositoryHash(): void
    {
        $files = new Filesystem();
        $files->dumpFile($this->fixtures.'/bench/change/tree/.gitignore', "one\n");
        $before = $this->read()->benchRepositoryHash;

        $files->dumpFile($this->fixtures.'/bench/change/tree/.gitignore', "two\n");

        self::assertNotSame($before, $this->read()->benchRepositoryHash);
    }

    public function testAChangedHiddenFileOfTheBaseChangesTheBenchRepositoryHash(): void
    {
        $files = new Filesystem();
        $files->dumpFile($this->fixtures.'/base/.editorconfig', "one\n");
        $before = $this->read()->benchRepositoryHash;

        $files->dumpFile($this->fixtures.'/base/.editorconfig', "two\n");

        self::assertNotSame($before, $this->read()->benchRepositoryHash);
    }

    public function testTheSameContentUnderTheBaseAndUnderTheBenchHashesDifferentlyFromMovingIt(): void
    {
        $before = $this->read()->benchRepositoryHash;

        new Filesystem()->rename($this->fixtures.'/base/a.js', $this->fixtures.'/bench/a.js');

        self::assertNotSame($before, $this->read()->benchRepositoryHash);
    }

    protected function setUp(): void
    {
        $this->fixtures = sys_get_temp_dir().'/intakt-provenance-'.bin2hex(random_bytes(4));
        $files = new Filesystem();
        $files->dumpFile($this->fixtures.'/base/a.js', "one\n");
        $files->dumpFile($this->fixtures.'/bench/change/tree/a.js', "two\n");
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->fixtures);
    }

    private function read(): BenchProvenance
    {
        return new ReadBenchProvenance(new HashedReviewPrompts(), $this->fixtures)->read();
    }
}
