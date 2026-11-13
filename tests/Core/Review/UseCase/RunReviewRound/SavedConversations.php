<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\TimeBasedUidInterface;

final class SavedConversations implements MessageStoreInterface
{
    /** @var list<string> */
    public array $ids = [];

    private readonly InMemoryMessageStore $inner;

    public function __construct()
    {
        $this->inner = new InMemoryMessageStore();
    }

    public function load(AbstractUid&TimeBasedUidInterface $id): MessageBag
    {
        return $this->inner->load($id);
    }

    public function save(MessageBag $messages): void
    {
        $this->ids[] = $messages->getId()->toRfc4122();
        $this->inner->save($messages);
    }

    public function delete(AbstractUid&TimeBasedUidInterface $id): void
    {
        $this->inner->delete($id);
    }
}
