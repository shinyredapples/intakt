<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolFactory\MemoryToolFactory;

// Covers nothing because the subject, AiServiceState, is a helper of the Symfony AI reset probe, not application code.
#[CoversNothing]
final class AiServiceStateTest extends TestCase
{
    public function testANewObjectThatStillHoldsStateIsReportedInPlaceOfTheOneItReplaced(): void
    {
        $before = AiServiceState::ofServices(['ai.toolbox' => $this->toolboxOf(new MemoryToolFactory())]);
        $after = AiServiceState::ofServices(['ai.toolbox' => $this->toolboxOf(new MemoryToolFactory()->addTool(new NamedTool(), 'kept_tool', 'A tool of the first run.'))]);

        self::assertSame([MemoryToolFactory::class.'::$tools'], $after->changedSince($before));
    }

    public function testANewObjectAsEmptyAsTheOneItReplacedLeavesNothingBehind(): void
    {
        $before = AiServiceState::ofServices(['ai.toolbox' => $this->toolboxOf(new MemoryToolFactory())]);
        $after = AiServiceState::ofServices(['ai.toolbox' => $this->toolboxOf(new MemoryToolFactory())]);

        self::assertSame([], $after->changedSince($before));
    }

    public function testAWritablePropertyOfAnObjectWithoutResetIsListedAsMutable(): void
    {
        $state = AiServiceState::ofServices(['ai.toolbox' => $this->toolboxOf(new MemoryToolFactory())]);

        self::assertContains(MemoryToolFactory::class.'::$tools', $state->mutableWithoutReset());
    }

    public function testAReadonlyPropertyOrAnObjectWithAResetIsNotListedAsMutable(): void
    {
        $state = AiServiceState::ofServices(['ai.toolbox' => $this->toolboxOf(new MemoryToolFactory()), 'ai.message_store' => new InMemoryMessageStore()]);

        self::assertNotContains(Toolbox::class.'::$toolFactory', $state->mutableWithoutReset());
        self::assertNotContains(InMemoryMessageStore::class.'::$messages', $state->mutableWithoutReset());
    }

    private function toolboxOf(MemoryToolFactory $factory): Toolbox
    {
        return new Toolbox([], $factory);
    }
}
