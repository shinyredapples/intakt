<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure\Repository;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Infrastructure\Repository\DenyPathsOutsideCheckout;
use Intakt\Core\Review\Infrastructure\Repository\ListDirectory;
use Intakt\Core\Review\Infrastructure\Repository\ReadFile;
use Intakt\Core\Review\Infrastructure\Repository\SearchFiles;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(SearchFiles::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(DenyPathsOutsideCheckout::class)]
#[UsesClass(ReadFile::class)]
#[UsesClass(ListDirectory::class)]
#[UsesClass(Checkout::class)]
final class SearchFilesTest extends WebTestCase
{
    use ScriptedRepositoryTools;

    public function testASearchOverTheRootDoesNotFollowSymlinksOutOrReadTheGitDirectory(): void
    {
        $answers = $this->runScript(new ToolCall('1', 'search_files', ['text' => 'secret']));

        self::assertSame('No match.', $answers[0]);
    }

    public function testASearchSkipsADirectoryItCannotReadAndStillFindsMatchesInTheRest(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('A mode has no effect on root.');
        }

        $this->fixture->write('locked/hidden.txt', 'total');
        $this->fixture->chmod('locked', 0000);

        try {
            $answers = $this->runScript(new ToolCall('1', 'search_files', ['text' => 'total']));
        } finally {
            $this->fixture->chmod('locked', 0755);
        }

        self::assertSame('src/Cart.php:2:// total the cart', $answers[0]);
    }

    public function testASearchOfOneFileOverTheSizeLimitFindsNothing(): void
    {
        $this->fixture->write('large.txt', 'needle'.str_repeat('x', 1_000_001));

        $answers = $this->runScript(new ToolCall('1', 'search_files', ['text' => 'needle', 'path' => 'large.txt']));

        self::assertSame('No match.', $answers[0]);
    }

    public function testALongMatchedLineIsCutSoMinifiedFilesCannotFloodTheAnswer(): void
    {
        foreach (range(0, 4) as $file) {
            $this->fixture->write("dist/min{$file}.js", 'needle'.str_repeat('x', 990_000));
        }

        $answers = $this->runScript(new ToolCall('1', 'search_files', ['text' => 'needle', 'path' => 'dist']));

        self::assertLessThan(5_000, strlen($answers[0]));
        self::assertSame(5, substr_count($answers[0], '[line cut]'));
    }

    public function testASearchAnswerStopsAtTheAnswerLimitWhenPathsAreLong(): void
    {
        $file = implode('/', array_fill(0, 4, str_repeat('d', 250))).'/hits.txt';
        $this->fixture->write($file, str_repeat("needle\n", 100));

        $answers = $this->runScript(new ToolCall('1', 'search_files', ['text' => 'needle']));

        self::assertLessThanOrEqual(100_100, strlen($answers[0]));
        self::assertStringEndsWith("\n[more matches not shown]", $answers[0]);
    }

    public function testASearchOfAFileWithManyMatchesStopsCollectingAtTheLimit(): void
    {
        $file = implode('/', array_fill(0, 4, str_repeat('d', 200))).'/hits.txt';
        $this->fixture->write($file, str_repeat("x\n", 50_000));
        memory_reset_peak_usage();
        $before = memory_get_usage();

        $this->runScript(new ToolCall('1', 'search_files', ['text' => 'x']));

        self::assertLessThan(10_000_000, memory_get_peak_usage() - $before);
    }
}
