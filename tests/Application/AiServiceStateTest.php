<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Agent\Toolbox\ChainToolbox;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolFactory\MemoryToolFactory;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\InMemoryModelCatalog;
use Symfony\AI\Platform\Test\MockModelCatalog;

// Covers nothing because the subject, AiServiceState, is a helper of the Symfony AI reset probe, not application code.
#[CoversNothing]
final class AiServiceStateTest extends TestCase
{
    public function testANewObjectThatStillHoldsStateIsReportedInPlaceOfTheOneItReplaced(): void
    {
        $before = AiServiceState::ofServices(['ai.toolbox' => $this->toolboxOf(new MemoryToolFactory())]);
        $after = AiServiceState::ofServices(['ai.toolbox' => $this->toolboxOf(new MemoryToolFactory()->addTool(new NamedTool(), 'kept_tool', 'A tool of the first run.'))]);

        self::assertContains(MemoryToolFactory::class.'::$tools', $after->changedSince($before));
    }

    public function testStateKeptThroughTheSecondReferenceToASharedObjectIsReportedWhenTheFirstIsReplaced(): void
    {
        $shared = new MemoryToolFactory();
        $before = AiServiceState::ofServices(['ai.toolbox' => new ChainToolbox([$this->toolboxOf($shared), $this->toolboxOf($shared)])]);
        $after = AiServiceState::ofServices(['ai.toolbox' => new ChainToolbox([$this->toolboxOf(new MemoryToolFactory()), $this->toolboxOf($shared->addTool(new NamedTool(), 'kept_tool', 'A tool of the first run.'))])]);

        self::assertContains(MemoryToolFactory::class.'::$tools', $after->changedSince($before));
    }

    public function testStateKeptThroughTheSecondReferenceIsReportedWhenTheSameOwnerGetsAnEmptyFirstReference(): void
    {
        $owner = $this->toolboxOf(new MemoryToolFactory());
        $sharedFactory = new MemoryToolFactory();
        $shared = $this->toolboxOf($sharedFactory);
        $instanceMap = new ReflectionProperty($owner, 'instanceMap');
        $instanceMap->setValue($owner, ['first' => $shared, 'second' => $shared]);
        $before = AiServiceState::ofServices(['ai.toolbox' => $owner]);
        $instanceMap->setValue($owner, ['first' => $this->toolboxOf(new MemoryToolFactory()), 'second' => $shared]);
        $sharedFactory->addTool(new NamedTool(), 'kept_tool', 'A tool of the first run.');

        $after = AiServiceState::ofServices(['ai.toolbox' => $owner]);

        self::assertContains(MemoryToolFactory::class.'::$tools', $after->changedSince($before));
    }

    public function testAnObjectThatRefersBackToAnAncestorEndsTheWalkThere(): void
    {
        $toolbox = $this->toolboxOf(new MemoryToolFactory());
        $chain = new ChainToolbox([$toolbox]);
        // The toolbox fills its instance map itself only while executing a tool, so the cycle back to its owner is put there directly.
        new ReflectionProperty($toolbox, 'instanceMap')->setValue($toolbox, ['owner' => $chain]);

        $state = AiServiceState::ofServices(['ai.toolbox' => $chain]);

        self::assertContains(MemoryToolFactory::class.'::$tools', $state->mutableWithoutReset());
    }

    public function testStateHeldByANewObjectThatReplacedACycleIsReportedAtItsNewPath(): void
    {
        $toolbox = $this->toolboxOf(new MemoryToolFactory());
        $instanceMap = new ReflectionProperty($toolbox, 'instanceMap');
        $instanceMap->setValue($toolbox, ['next' => $toolbox]);
        $before = AiServiceState::ofServices(['ai.toolbox' => $toolbox]);
        $instanceMap->setValue($toolbox, ['next' => $this->toolboxOf(new MemoryToolFactory()->addTool(new NamedTool(), 'kept_tool', 'A tool of the first run.'))]);

        $after = AiServiceState::ofServices(['ai.toolbox' => $toolbox]);

        self::assertContains(MemoryToolFactory::class.'::$tools', $after->changedSince($before));
    }

    public function testAPathThatOnlyTheEarlierWalkReachedIsReported(): void
    {
        $toolbox = $this->toolboxOf(new MemoryToolFactory());
        $instanceMap = new ReflectionProperty($toolbox, 'instanceMap');
        $instanceMap->setValue($toolbox, ['kept' => $this->toolboxOf(new MemoryToolFactory())]);
        $before = AiServiceState::ofServices(['ai.toolbox' => $toolbox]);
        $instanceMap->setValue($toolbox, []);

        $after = AiServiceState::ofServices(['ai.toolbox' => $toolbox]);

        self::assertContains(MemoryToolFactory::class.'::$tools', $after->changedSince($before));
    }

    // Both catalogs record properties under the same path, so the walk compares property lists there, not only paths; the parent's private properties are invisible to the walk, so no library pair keeps a property and adds one.
    public function testAPropertyOnlyTheNewObjectAtAnExistingPathDeclaresIsReported(): void
    {
        $before = AiServiceState::ofServices(['ai.catalog' => new InMemoryModelCatalog()]);
        $after = AiServiceState::ofServices(['ai.catalog' => new MockModelCatalog(['model-of-the-first-run' => ['class' => Model::class, 'capabilities' => []]])]);

        self::assertContains(MockModelCatalog::class.'::$models', $after->changedSince($before));
    }

    public function testAPropertyOnlyTheReplacedObjectAtAnExistingPathDeclaredIsReported(): void
    {
        $before = AiServiceState::ofServices(['ai.catalog' => new MockModelCatalog(['model-of-the-first-run' => ['class' => Model::class, 'capabilities' => []]])]);
        $after = AiServiceState::ofServices(['ai.catalog' => new InMemoryModelCatalog()]);

        self::assertContains(MockModelCatalog::class.'::$models', $after->changedSince($before));
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
