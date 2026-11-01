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
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(DenyPathsOutsideCheckout::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(ReadFile::class)]
#[UsesClass(ListDirectory::class)]
#[UsesClass(SearchFiles::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(PathOutsideCheckout::class)]
#[UsesClass(PathNotInCheckout::class)]
final class DenyPathsOutsideCheckoutTest extends WebTestCase
{
    use ScriptedRepositoryTools;

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
}
