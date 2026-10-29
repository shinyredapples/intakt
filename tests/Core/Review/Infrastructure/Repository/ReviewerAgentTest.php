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
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Agent\Toolbox\TraceableToolbox;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;

#[CoversClass(ReviewerToolboxFactory::class)]
#[CoversClass(DenyPathsOutsideCheckout::class)]
#[CoversClass(ReadFile::class)]
#[CoversClass(ListDirectory::class)]
#[CoversClass(SearchFiles::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(PathOutsideCheckout::class)]
#[UsesClass(PathNotInCheckout::class)]
final class ReviewerAgentTest extends TestCase
{
    public function testTheModelReadsAFileAtTheHeadThroughTheTools(): void
    {
        $answers = $this->runScript(new ToolCall('1', 'read_file', ['path' => 'src/Cart.php']));

        self::assertStringContainsString('final class Cart', $answers[0]);
    }

    public function testTheModelListsADirectoryAndSearchesTheHead(): void
    {
        $answers = $this->runScript(
            new ToolCall('1', 'list_directory', ['path' => '.']),
            new ToolCall('2', 'search_files', ['text' => 'total']),
        );

        self::assertSame("README.md\nescape/\nsrc/", $answers[0]);
        self::assertSame('src/Cart.php:2:// total the cart', $answers[1]);
    }

    public function testACallWithAParentPathIsRefusedAndNeverReadsTheFile(): void
    {
        $answers = $this->runScript(new ToolCall('1', 'read_file', ['path' => '../outside/secret.txt']));

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

    public function testASearchOverTheRootDoesNotFollowSymlinksOutOrReadTheGitDirectory(): void
    {
        $answers = $this->runScript(new ToolCall('1', 'search_files', ['text' => 'secret']));

        self::assertSame('No match.', $answers[0]);
    }

    public function testAFailingToolAnswersTheModelWithAnErrorInsteadOfCrashing(): void
    {
        $answers = $this->runScript(new ToolCall('1', 'read_file', ['path' => 'src/Missing.php']));

        self::assertSame('An error occurred while executing tool "read_file".', $answers[0]);
    }

    public function testEveryRepositoryToolIsReadOnlyAndNamesItsPathArgumentAsTheListenerExpects(): void
    {
        $fixture = new ReviewCheckout();
        $tools = new ReviewerToolboxFactory()->create($fixture->checkout)->getTools();

        self::assertCount(3, $tools);

        foreach ($tools as $tool) {
            self::assertTrue($tool->getEffectHints()->readOnlyHint, $tool->getName());
            self::assertArrayHasKey(DenyPathsOutsideCheckout::PATH_ARGUMENT, $tool->getParameters()['properties'] ?? [], $tool->getName());
        }
    }

    /**
     * @return list<string>
     */
    private function runScript(ToolCall ...$calls): array
    {
        $fixture = new ReviewCheckout();
        $toolbox = new TraceableToolbox(new ReviewerToolboxFactory()->create($fixture->checkout));
        $step = 0;
        $platform = MockPlatformFactory::createPlatform(static function () use (&$step, $calls) {
            return $step < count($calls) ? new ToolCallResult([$calls[$step++]]) : new TextResult('done');
        });

        $result = new Agent($platform, 'reviewer', toolbox: $toolbox)->call(new MessageBag())->getContent();

        self::assertSame('done', $result);

        return array_values(array_map(static function (ToolResult $call): string {
            $answer = $call->getResult();
            self::assertIsString($answer);

            return $answer;
        }, $toolbox->getCalls()));
    }
}
