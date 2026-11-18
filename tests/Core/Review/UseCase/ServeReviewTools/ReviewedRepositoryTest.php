<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ServeReviewTools;

use Closure;
use Intakt\Core\Review\Contract\CheckoutNotADirectory;
use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\ReviewTarget;
use Intakt\Core\Review\Infrastructure\Repository\EntryNotReadable;
use Intakt\Core\Review\Infrastructure\Repository\SearchTextEmpty;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewedRepository;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\KernelTestCase;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Uid\Uuid;
use Throwable;

#[Group('persistence')]
#[CoversClass(ReviewedRepository::class)]
#[UsesClass(CheckoutNotADirectory::class)]
#[UsesClass(EntryNotReadable::class)]
#[UsesClass(SearchTextEmpty::class)]
final class ReviewedRepositoryTest extends KernelTestCase
{
    private RoundFixture $fixture;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testAFailureThatIsNoRefusalPassesThroughInsteadOfBecomingAToolError(): void
    {
        $repository = $this->repositoryFor($this->fixture->start(1)->id()->toRfc4122())->reporting(static function (int $searched): void {
            throw new RuntimeException(sprintf('Progress %d could not be sent.', $searched));
        });

        try {
            $repository->searchFiles('RoundFixture', '.');
            self::fail('The search did not fail.');
        } catch (Throwable $failure) {
            self::assertSame(RuntimeException::class, $failure::class);
        }
    }

    public function testAnEmptySearchTextIsStillRefusedAsAToolError(): void
    {
        $repository = $this->repositoryFor($this->fixture->start(1)->id()->toRfc4122());

        self::assertInstanceOf(SearchTextEmpty::class, $this->refusalOf(static fn (): string => $repository->searchFiles('', '.')));
    }

    public function testReadingADirectoryIsStillRefusedAsAToolError(): void
    {
        $repository = $this->repositoryFor($this->fixture->start(1)->id()->toRfc4122());

        self::assertInstanceOf(EntryNotReadable::class, $this->refusalOf(static fn (): string => $repository->readFile('.')));
    }

    public function testListingAFileIsStillRefusedAsAToolError(): void
    {
        $repository = $this->repositoryFor($this->fixture->start(1)->id()->toRfc4122());

        self::assertInstanceOf(EntryNotReadable::class, $this->refusalOf(static fn (): string => $repository->listDirectory('RoundFixture.php')));
    }

    public function testAHeadThatIsGoneIsStillRefusedAsAToolError(): void
    {
        $repository = $this->repositoryFor($this->roundWithoutHead());

        self::assertInstanceOf(CheckoutNotADirectory::class, $this->refusalOf(static fn (): string => $repository->readFile('README.md')));
    }

    private function repositoryFor(string $roundId): ReviewedRepository
    {
        $storage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken(new PostAuthenticationToken(new InMemoryUser($roundId, null, [ReviewCredential::REVIEWER_ROLE]), 'mcp_review', [ReviewCredential::REVIEWER_ROLE]));
        $repository = self::getContainer()->get(ReviewedRepository::class);
        self::assertInstanceOf(ReviewedRepository::class, $repository);

        return $repository;
    }

    /**
     * @param Closure(): string $call
     */
    private function refusalOf(Closure $call): ?Throwable
    {
        try {
            $call();
        } catch (ToolCallException $refused) {
            return $refused->getPrevious();
        }

        self::fail('The call was not refused.');
    }

    // Never created, because the snapshot of a round can be removed while its credential is still valid.
    private function roundWithoutHead(): string
    {
        $id = Uuid::v4();
        $this->fixture->entities->persist(new ReviewRound($id, new ReviewTarget(new ReviewSubject('beta', sys_get_temp_dir().'/intakt-gone-head-'.$id->toRfc4122(), 'Task', ''), SampleIdentity::any()), new ChangeRound('gone', 1, null)));
        $this->fixture->entities->flush();

        return $id->toRfc4122();
    }
}
