<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Shadow\UseCase\ReadLoopReviews;

use Intakt\Core\Shadow\Domain\LoopReviewReader;
use Intakt\Core\Shadow\UseCase\ReadLoopReviews\ReadLoopReviewsCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ReadLoopReviewsCommand::class)]
#[UsesClass(LoopReviewReader::class)]
final class ReadLoopReviewsCommandTest extends TestCase
{
    public function testTheCommandPrintsTheReaderOutputForTheRecordedComments(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['file' => __DIR__.'/../../../../Fixtures/Shadow/loop-review-comments.json']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(
            "correctness: changes\n  P1 src/A.php:10\n  P3 src/B.php:7\nstyle: approve\nsecurity: unreadable\ndocs: changes\n  P2 docs/x.md:3\n4 loop reviews read\n",
            $tester->getDisplay(),
        );
    }

    public function testAMissingFileIsAnInputError(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['file' => 'does-not-exist.json']);

        self::assertSame(Command::INVALID, $status);
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ReadLoopReviewsCommand(new LoopReviewReader()));

        return new CommandTester($application->find('intakt:shadow:read-loop-reviews'));
    }
}
