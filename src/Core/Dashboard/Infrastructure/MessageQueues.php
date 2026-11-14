<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

// Counted in the transport's own store, so a stopped consumer shows as messages piling up instead of as no status.
final readonly class MessageQueues
{
    /**
     * @param iterable<string, ReceiverInterface> $receivers
     */
    public function __construct(
        #[AutowireIterator('messenger.receiver', indexAttribute: 'alias')]
        private iterable $receivers,
    ) {
    }

    /**
     * @return list<array{name: string, messages: ?int}>
     */
    public function now(): array
    {
        $queues = [];

        foreach ($this->receivers as $name => $receiver) {
            $queues[] = ['name' => $name, 'messages' => $receiver instanceof MessageCountAwareInterface ? $receiver->getMessageCount() : null];
        }

        return $queues;
    }
}
