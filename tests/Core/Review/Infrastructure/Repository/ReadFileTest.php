<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure\Repository;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\PathNotInCheckout;
use Intakt\Core\Review\Infrastructure\Repository\DenyPathsOutsideCheckout;
use Intakt\Core\Review\Infrastructure\Repository\ListDirectory;
use Intakt\Core\Review\Infrastructure\Repository\ReadFile;
use Intakt\Core\Review\Infrastructure\Repository\SearchFiles;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(ReadFile::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(DenyPathsOutsideCheckout::class)]
#[UsesClass(ListDirectory::class)]
#[UsesClass(SearchFiles::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(PathNotInCheckout::class)]
final class ReadFileTest extends WebTestCase
{
    use ScriptedRepositoryTools;

    public function testTheModelReadsAFileAtTheHeadThroughTheTools(): void
    {
        $answers = $this->runScript(new ToolCall('1', 'read_file', ['path' => 'src/Cart.php']));

        self::assertToolCalled('read_file');
        self::assertPlatformCallCount(2);
        self::assertStringContainsString('final class Cart', $answers[0]);
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

    public function testAFailingToolAnswersTheModelWithAnErrorInsteadOfCrashing(): void
    {
        $answers = $this->runScript(new ToolCall('1', 'read_file', ['path' => 'src/Missing.php']));

        self::assertSame('An error occurred while executing tool "read_file".', $answers[0]);
    }
}
