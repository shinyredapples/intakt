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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Agent\Agent;
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
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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

    private static ?DataCollector $collector = null;

    private ReviewCheckout $fixture;

    protected function setUp(): void
    {
        $this->fixture = new ReviewCheckout();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        self::$collector = null;
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

    public function testASearchOfOneFileOverTheSizeLimitFindsNothing(): void
    {
        $this->fixture->write('large.txt', 'needle'.str_repeat('x', 1_000_001));

        $answers = $this->runScript(new ToolCall('1', 'search_files', ['text' => 'needle', 'path' => 'large.txt']));

        self::assertSame('No match.', $answers[0]);
    }

    public function testABinaryFileAnswersTheModelWithAnErrorThatCanBeSentAsJson(): void
    {
        $this->fixture->write('logo.png', "\x89PNG\r\n\x1a\n\xff\xfe\x00 total");

        $answers = $this->runScript(
            new ToolCall('1', 'read_file', ['path' => 'logo.png']),
            new ToolCall('2', 'search_files', ['text' => 'total']),
        );

        self::assertSame('An error occurred while executing tool "read_file".', $answers[0]);
        self::assertSame('src/Cart.php:2:// total the cart', $answers[1]);
    }

    public function testAFileCutAtTheLimitEndsOnAWholeCharacter(): void
    {
        $this->fixture->write('long.txt', 'a'.str_repeat('é', 60_000));

        $answers = $this->runScript(new ToolCall('1', 'read_file', ['path' => 'long.txt']));

        self::assertTrue(mb_check_encoding($answers[0], 'UTF-8'));
        self::assertStringEndsWith("é\n[truncated]", $answers[0]);
    }

    public function testAPathThatIsNotAStringIsDenied(): void
    {
        $answers = $this->runScript(new ToolCall('1', 'read_file', ['path' => 42]));

        self::assertSame('The path must be a string.', $answers[0]);
    }

    public function testAFailingToolAnswersTheModelWithAnErrorInsteadOfCrashing(): void
    {
        $answers = $this->runScript(new ToolCall('1', 'read_file', ['path' => 'src/Missing.php']));

        self::assertSame('An error occurred while executing tool "read_file".', $answers[0]);
    }

    public function testEveryRepositoryToolIsReadOnlyAndNamesItsPathArgumentAsTheListenerExpects(): void
    {
        $tools = new ReviewerToolboxFactory()->create($this->fixture->checkout)->getTools();

        self::assertCount(3, $tools);

        foreach ($tools as $tool) {
            self::assertTrue($tool->getEffectHints()->readOnlyHint, $tool->getName());
            self::assertArrayHasKey(DenyPathsOutsideCheckout::PATH_ARGUMENT, $tool->getParameters()['properties'] ?? [], $tool->getName());
        }
    }

    // Because the toolbox is built per checkout and is no container service, the collector is built from this run's traceable platform and toolbox instead of a profiled request.
    protected static function getAiDataCollector(): DataCollector
    {
        self::assertNotNull(self::$collector, 'No scripted run was made.');

        return self::$collector;
    }

    /**
     * @return list<string>
     */
    private function runScript(ToolCall ...$calls): array
    {
        $toolbox = new TraceableToolbox(new ReviewerToolboxFactory()->create($this->fixture->checkout));
        $step = 0;
        $platform = new TraceablePlatform(MockPlatformFactory::createPlatform(static function () use (&$step, $calls) {
            return $step < count($calls) ? new ToolCallResult([$calls[$step++]]) : new TextResult('done');
        }));

        $result = new Agent($platform, 'reviewer', toolbox: $toolbox)->call(new MessageBag())->getContent();

        self::assertSame('done', $result);
        self::$collector = new DataCollector([$platform], [$toolbox], [$toolbox], [], [], []);
        self::$collector->lateCollect();

        return array_values(array_map(static function (ToolResult $call): string {
            $answer = $call->getResult();
            self::assertIsString($answer);
            self::assertNotFalse(json_encode($answer));

            return $answer;
        }, $toolbox->getCalls()));
    }
}
