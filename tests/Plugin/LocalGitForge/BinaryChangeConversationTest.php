<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Plugin\Forge\Contract\BinaryFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Uid\UuidV7;

// Through the stored conversation, because the reviewer's conversation holds the change's diff and is stored as JSON before the model is called, which is where a PNG's bytes failed a review with NotEncodableValueException.
#[Group('persistence')]
#[CoversClass(LocalGitForge::class)]
#[CoversClass(BinaryFiles::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(NameStatusParser::class)]
final class BinaryChangeConversationTest extends KernelTestCase
{
    private TemporaryRepository $repository;

    public function testAReviewerConversationHoldingTheDiffOfAChangeThatAddsAPngIsStored(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('.evidence/shot.png', "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x02\0\0\0");
        $patch = new LocalGitForge(new LocalRepository($this->repository->path, 'main'), new NameStatusParser())->change(new ChangeId('feature'))->diff->patch;
        $store = $this->migratedStore();
        $conversation = new UuidV7();

        $store->save(MessageBag::forId($conversation, Message::forSystem('You review.'), Message::ofUser($patch)));

        $stored = $store->load($conversation)->getUserMessage();
        self::assertInstanceOf(UserMessage::class, $stored);
        self::assertStringContainsString('Binary file, not shown: absent at the base, 29 bytes at the head', $stored->asText() ?? '');
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
        parent::tearDown();
    }

    private function migratedStore(): MessageStoreInterface
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);
        $console = new ApplicationTester($application);
        EmptyDatabase::in(self::getContainer());
        $console->run(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]);
        $console->assertCommandIsSuccessful($console->getDisplay());

        $store = self::getContainer()->get(MessageStoreInterface::class);
        self::assertInstanceOf(MessageStoreInterface::class, $store);

        return $store;
    }
}
