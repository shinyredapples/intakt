<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ServeReviewTools;

use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\ReviewTarget;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewedRepository;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\KernelTestCase;
use Mcp\Exception\ToolCallException;
use OverflowException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Uid\Uuid;

#[Group('persistence')]
#[CoversClass(ReviewedRepository::class)]
final class ReviewedRepositoryTest extends KernelTestCase
{
    private RoundFixture $fixture;

    private ReviewedRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->fixture = new RoundFixture(self::getContainer());
        $repository = self::getContainer()->get(ReviewedRepository::class);
        self::assertInstanceOf(ReviewedRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testAnUnexpectedFailureDuringASearchPassesThroughInsteadOfBecomingARefusal(): void
    {
        $this->signInAs($this->fixture->start(1)->id()->toRfc4122());

        $this->expectException(OverflowException::class);
        $this->expectExceptionMessageIs('The progress could not be sent.');

        $this->repository->reporting(static fn (int $searched): never => throw new OverflowException('The progress could not be sent.'))->searchFiles('RoundFixture', '.');
    }

    public function testARoundWhoseCheckoutIsGoneAnswersThatItsHeadIsNotAvailable(): void
    {
        $this->signInAs($this->roundAt(sys_get_temp_dir().'/intakt-gone-'.Uuid::v4()->toRfc4122()));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageIs('The head of this round is not available.');

        $this->repository->readFile('README.md');
    }

    public function testReadingAPathThatIsNoFileIsStillRefusedAsAToolError(): void
    {
        $this->signInAs($this->fixture->start(1)->id()->toRfc4122());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageIsOrContains('"." is not a file.');

        $this->repository->readFile('.');
    }

    public function testListingAFileIsStillRefusedAsAToolError(): void
    {
        $this->signInAs($this->fixture->start(1)->id()->toRfc4122());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageIsOrContains('"RoundFixture.php" is not a directory.');

        $this->repository->listDirectory('RoundFixture.php');
    }

    public function testSearchingForAnEmptyTextIsStillRefusedAsAToolError(): void
    {
        $this->signInAs($this->fixture->start(1)->id()->toRfc4122());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageIsOrContains('The text to find is empty.');

        $this->repository->searchFiles('', '.');
    }

    public function testAMissingPathIsStillRefusedAsAToolError(): void
    {
        $this->signInAs($this->fixture->start(1)->id()->toRfc4122());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageIsOrContains('does not exist at the head under review');

        $this->repository->readFile('missing.php');
    }

    private function signInAs(string $round): void
    {
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser($round, null, [ReviewCredential::REVIEWER_ROLE]), 'mcp_review', [ReviewCredential::REVIEWER_ROLE]));
    }

    private function roundAt(string $root): string
    {
        $id = Uuid::v4();
        $this->fixture->entities->persist(new ReviewRound($id, new ReviewTarget(new ReviewSubject('beta', $root, 'Task', ''), SampleIdentity::any()), new ChangeRound('gone', 1, null)));
        $this->fixture->entities->flush();

        return $id->toRfc4122();
    }
}
