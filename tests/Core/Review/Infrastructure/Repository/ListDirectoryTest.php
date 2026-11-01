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

#[CoversClass(ListDirectory::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(DenyPathsOutsideCheckout::class)]
#[UsesClass(ReadFile::class)]
#[UsesClass(SearchFiles::class)]
#[UsesClass(Checkout::class)]
final class ListDirectoryTest extends WebTestCase
{
    use ScriptedRepositoryTools;

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

    public function testAListingStopsAtTheAnswerLimitWhenADirectoryIsLarge(): void
    {
        foreach (range(0, 999) as $file) {
            $this->fixture->write('many/'.str_repeat('f', 200).$file, '');
        }

        $answers = $this->runScript(new ToolCall('1', 'list_directory', ['path' => 'many']));

        self::assertLessThanOrEqual(100_100, strlen($answers[0]));
        self::assertStringEndsWith("\n[more entries not shown]", $answers[0]);
    }
}
