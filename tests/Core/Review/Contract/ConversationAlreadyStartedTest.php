<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\ConversationAlreadyStarted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(ConversationAlreadyStarted::class)]
final class ConversationAlreadyStartedTest extends TestCase
{
    public function testTheMessageNamesTheConversationThatWasAlreadyStarted(): void
    {
        $conversation = new UuidV7();

        $refused = ConversationAlreadyStarted::for($conversation, new RuntimeException('Taken.'));

        self::assertStringContainsString('"'.$conversation->toRfc4122().'" was already started', $refused->getMessage());
    }

    public function testTheRefusalOfTheStoreIsKeptAsTheCause(): void
    {
        $taken = new RuntimeException('Another review inserted the conversation first.');

        $refused = ConversationAlreadyStarted::for(new UuidV7(), $taken);

        self::assertSame($taken, $refused->getPrevious());
    }
}
