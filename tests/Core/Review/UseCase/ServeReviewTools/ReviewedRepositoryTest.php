<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ServeReviewTools;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\CheckoutNotADirectory;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Contract\RoundUpdates;
use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\ReviewTarget;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Infrastructure\Repository\PathNotReadable;
use Intakt\Core\Review\Infrastructure\Repository\SearchTextEmpty;
use Intakt\Core\Review\Infrastructure\RoundToolCalls;
use Intakt\Core\Review\UseCase\ReadRound\ReadableRounds;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewedRepository;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\KernelTestCase;
use InvalidArgumentException;
use Mcp\Exception\ToolCallException;
use OverflowException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Uid\Uuid;

#[Group('persistence')]
#[CoversClass(ReviewedRepository::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(CheckoutNotADirectory::class)]
#[UsesClass(PathNotReadable::class)]
#[UsesClass(SearchTextEmpty::class)]
final class ReviewedRepositoryTest extends KernelTestCase
{
    private RoundFixture $fixture;

    private ReviewedRepository $repository;

    public function testAnUnexpectedFailureDuringASearchPassesThroughInsteadOfBecomingARefusal(): void
    {
        $this->signInAs($this->fixture->start(1)->id()->toRfc4122());

        $this->expectException(OverflowException::class);
        $this->expectExceptionMessageIs('The progress could not be sent.');

        $this->repository->reporting(static fn (int $searched): never => throw new OverflowException('The progress could not be sent.'))->searchFiles('RoundFixture', '.');
    }

    public function testAnUnexpectedFailureWhileFindingTheHeadPassesThroughInsteadOfBecomingAToolError(): void
    {
        $this->signInAs(Uuid::v4()->toRfc4122());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The round could not be loaded.');

        $this->repositoryHolding($this->roundThatFailsToLoad())->readFile('README.md');
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

    protected function setUp(): void
    {
        self::bootKernel();
        $this->fixture = new RoundFixture(self::getContainer());
        $repository = self::getContainer()->get(ReviewedRepository::class);
        self::assertInstanceOf(ReviewedRepository::class, $repository);
        $this->repository = $repository;
    }

    private function signInAs(string $round): void
    {
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser($round, null, [ReviewCredential::REVIEWER_ROLE]), 'mcp_review', [ReviewCredential::REVIEWER_ROLE]));
    }

    // Lazy, with only its state loaded, so the round fails while it is asked for its checkout, inside the step that turns a gone head into a tool error.
    private function roundThatFailsToLoad(): ReviewRound
    {
        $class = new ReflectionClass(ReviewRound::class);
        $round = $class->newLazyGhost(static fn (ReviewRound $round): never => throw new InvalidArgumentException('The round could not be loaded.'));
        $class->getProperty('state')->setRawValueWithoutLazyInitialization($round, RoundState::Collecting);

        return $round;
    }

    private function repositoryHolding(ReviewRound $round): ReviewedRepository
    {
        $entities = self::createStub(EntityManagerInterface::class);
        $entities->method('find')->willReturn($round);

        return new ReviewedRepository(
            new ReadableRounds($this->fixture->transactionsOn($entities)),
            $this->service(Security::class),
            $this->service(Fence::class),
            $this->service(RoundToolCalls::class),
            self::createStub(RoundUpdates::class),
            $this->service(PromptAssembly::class),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    private function roundAt(string $root): string
    {
        $id = Uuid::v4();
        $this->fixture->entities->persist(new ReviewRound($id, new ReviewTarget(new ReviewSubject('beta', $root, 'Task', ''), SampleIdentity::any()), new ChangeRound('gone', 1, null)));
        $this->fixture->entities->flush();

        return $id->toRfc4122();
    }
}
