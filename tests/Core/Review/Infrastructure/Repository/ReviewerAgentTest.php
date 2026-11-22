<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure\Repository;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\PathNotInCheckout;
use Intakt\Core\Review\Contract\PathOutsideCheckout;
use Intakt\Core\Review\Infrastructure\Repository\DenyPathsOutsideCheckout;
use Intakt\Core\Review\Infrastructure\Repository\ListDirectory;
use Intakt\Core\Review\Infrastructure\Repository\ReadFile;
use Intakt\Core\Review\Infrastructure\Repository\SearchFiles;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Agent\Toolbox\TraceableToolbox;
use Symfony\AI\AiBundle\Profiler\DataCollector;
use Symfony\AI\AiBundle\Test\AiAssertionsTrait;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TraceablePlatform;

#[CoversClass(ReviewerToolboxFactory::class)]
#[CoversClass(DenyPathsOutsideCheckout::class)]
#[CoversClass(ReadFile::class)]
#[CoversClass(ListDirectory::class)]
#[CoversClass(SearchFiles::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(PathOutsideCheckout::class)]
#[UsesClass(PathNotInCheckout::class)]
final class ReviewerAgentTest extends WebTestCase
{
    use AiAssertionsTrait;

    private const string COLLECTOR = 'test.reviewer_agent.ai_data_collector';

    private ReviewCheckout $fixture;

    protected function setUp(): void
    {
        $this->fixture = new ReviewCheckout();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function testTheModelReadsAFileAtTheHeadThroughTheTools(): void
    {
        $answers = $this->runScript(new ToolCall('1', 'read_file', ['path' => 'src/Cart.php']));

        self::assertToolCalled('read_file');
        self::assertPlatformCallCount(2);
        self::assertStringContainsString('final class Cart', $answers[0]);
    }

    public function testTheModelListsADirectoryAndSearchesTheHead(): void
    {
        $answers = $this->runScript(
            new ToolCall('1', 'list_directory', ['path' => '.']),
            new ToolCall('2', 'search_files', ['text' => 'total']),
        );

        self::assertToolCalled('list_directory');
        self::assertToolCalled('search_files');
        self::assertPlatformCallCount(3);
        self::assertSame("README.md\nescape/\nsrc/", $answers[0]);
        self::assertSame('src/Cart.php:2:// total the cart', $answers[1]);
    }

    public function testACallWithAParentPathIsRefusedAndNeverReadsTheFile(): void
    {
        $answers = $this->runScript(new ToolCall('1', 'read_file', ['path' => '../outside/secret.txt']));

        self::assertToolCalled('read_file');
        self::assertStringContainsString('outside the repository', $answers[0]);
        self::assertStringNotContainsString('outside secret', $answers[0]);
    }

    public function testACallWithAnAbsolutePathIsRefused(): void
    {
        $answers = $this->runScript(new ToolCall('1', 'list_directory', ['path' => '/etc']));

        self::assertStringContainsString('outside the repository', $answers[0]);
    }

    public function testACallThroughASymlinkOutOfTheCheckoutIsRefused(): void
    {
        $answers = $this->runScript(new ToolCall('1', 'search_files', ['text' => 'secret', 'path' => 'escape']));

        self::assertStringContainsString('outside the repository', $answers[0]);
    }

    public function testACallThroughALinkToTheGitDirectoryNeverReturnsItsContent(): void
    {
        $this->fixture->link('alias', '.git');

        $answers = $this->runScript(
            new ToolCall('1', 'read_file', ['path' => 'alias/config']),
            new ToolCall('2', 'search_files', ['text' => 'secret', 'path' => 'alias']),
            new ToolCall('3', 'list_directory', ['path' => 'alias']),
        );

        foreach ($answers as $answer) {
            self::assertStringContainsString('outside the repository', $answer);
            self::assertStringNotContainsString('secret-config', $answer);
        }
    }

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

    public function testAListingStopsAtTheAnswerLimitWhenADirectoryIsLarge(): void
    {
        foreach (range(0, 999) as $file) {
            $this->fixture->write('many/'.str_repeat('f', 200).$file, '');
        }

        $answers = $this->runScript(new ToolCall('1', 'list_directory', ['path' => 'many']));

        self::assertLessThanOrEqual(100_100, strlen($answers[0]));
        self::assertStringEndsWith("\n[more entries not shown]", $answers[0]);
    }

    public function testABinaryFileIsNamedAsBinaryWithItsSizeInAnAnswerThatCanBeSentAsJson(): void
    {
        $this->fixture->write('logo.png', "\x89PNG\r\n\x1a\n\xff\xfe\x00 total");

        $answers = $this->runScript(
            new ToolCall('1', 'read_file', ['path' => 'logo.png']),
            new ToolCall('2', 'search_files', ['text' => 'total']),
        );

        self::assertSame('Binary file, not shown: 17 bytes', $answers[0]);
        self::assertSame('src/Cart.php:2:// total the cart', $answers[1]);
    }

    public function testAFileWhoseNulByteLiesPastTheReadLimitIsNamedAsBinary(): void
    {
        $this->fixture->write('large.bin', str_repeat('a', 150_000)."\0");

        $answers = $this->runScript(new ToolCall('1', 'read_file', ['path' => 'large.bin']));

        self::assertSame('Binary file, not shown: 150001 bytes', $answers[0]);
    }

    public function testALargeFileWhoseNulByteLiesWithinTheReadLimitIsNamedAsBinaryWithItsWholeSize(): void
    {
        $this->fixture->write('early.bin', "\0".str_repeat('a', 150_000));

        $answers = $this->runScript(new ToolCall('1', 'read_file', ['path' => 'early.bin']));

        self::assertSame('Binary file, not shown: 150001 bytes', $answers[0]);
    }

    public function testAFileWithoutANulByteThatIsNotUtf8AnswersTheModelWithAnError(): void
    {
        $this->fixture->write('latin1.txt', "caf\xe9\n");

        $answers = $this->runScript(new ToolCall('1', 'read_file', ['path' => 'latin1.txt']));

        self::assertSame('An error occurred while executing tool "read_file".', $answers[0]);
    }

    public function testAFileCutAtTheLimitEndsOnAWholeCharacter(): void
    {
        $this->fixture->write('long.txt', 'a'.str_repeat('é', 60_000));

        $answers = $this->runScript(new ToolCall('1', 'read_file', ['path' => 'long.txt']));

        self::assertTrue(mb_check_encoding($answers[0], 'UTF-8'));
        self::assertStringEndsWith("é\n[truncated]", $answers[0]);
    }

    public function testAPathThatIsNotAStringIsRefusedAsAnInvalidArgument(): void
    {
        $answers = $this->runScript(new ToolCall('1', 'read_file', ['path' => 42]));

        self::assertSame('The path must be a string.', $answers[0]);
    }

    public function testAMissingArgumentIsRefusedBeforeAnEscapingPathIsDenied(): void
    {
        $answers = $this->runScript(new ToolCall('1', 'search_files', ['path' => '/etc']));

        self::assertSame('Parameter "text" is mandatory for tool "search_files".', $answers[0]);
    }

    public function testAFailingToolAnswersTheModelWithAnErrorInsteadOfCrashing(): void
    {
        $answers = $this->runScript(new ToolCall('1', 'read_file', ['path' => 'src/Missing.php']));

        self::assertSame('An error occurred while executing tool "read_file".', $answers[0]);
    }

    public function testEveryRepositoryToolIsReadOnlyAndNamesItsPathArgumentAsTheListenerExpects(): void
    {
        $tools = new ReviewerToolboxFactory()->inCheckout($this->fixture->checkout)->getTools();

        self::assertCount(3, $tools);

        foreach ($tools as $tool) {
            self::assertTrue($tool->getEffectHints()->readOnlyHint, $tool->getName());
            self::assertArrayHasKey(DenyPathsOutsideCheckout::PATH_ARGUMENT, $tool->getParameters()['properties'] ?? [], $tool->getName());
        }
    }

    // Because the toolbox is built per checkout and is no container service, the collector is built from this run's traceable platform and toolbox instead of a profiled request.
    protected static function getAiDataCollector(): DataCollector
    {
        self::assertTrue(self::getContainer()->has(self::COLLECTOR), 'No scripted run was made.');
        $collector = self::getContainer()->get(self::COLLECTOR);
        self::assertInstanceOf(DataCollector::class, $collector);

        return $collector;
    }

    /**
     * @return list<string>
     */
    private function runScript(ToolCall ...$calls): array
    {
        $toolbox = new TraceableToolbox(new FaultTolerantToolbox(new ReviewerToolboxFactory()->inCheckout($this->fixture->checkout)));
        $step = 0;
        $platform = new TraceablePlatform(MockPlatformFactory::createPlatform(static function () use (&$step, $calls) {
            return $step < count($calls) ? new ToolCallResult([$calls[$step++]]) : new TextResult('done');
        }));

        $result = new Agent($platform, 'reviewer', toolbox: $toolbox)->call(new MessageBag())->getContent();

        self::assertSame('done', $result);
        $collector = new DataCollector([$platform], [$toolbox], [$toolbox], [], [], []);
        $collector->lateCollect();
        self::getContainer()->set(self::COLLECTOR, $collector);

        return array_values(array_map(static function (ToolResult $call): string {
            $answer = $call->getResult();
            self::assertIsString($answer);
            self::assertNotFalse(json_encode($answer));

            return $answer;
        }, $toolbox->getCalls()));
    }
}
