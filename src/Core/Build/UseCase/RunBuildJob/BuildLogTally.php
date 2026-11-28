<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Collects what the events of one log show, one event at a time, in the order the CLI wrote them.
#[Exclude]
final class BuildLogTally
{
    /** @var array<string, int> */
    private array $perMessage = [];

    private int $resultTokens = 0;

    /** @var list<string> */
    private array $changes = [];

    /** @var array<string, bool> call id => whether the call runs the tests or the checks */
    private array $open = [];

    /** @var array<string, string> */
    private array $failing = [];

    /**
     * @param array<mixed> $event
     */
    public function take(array $event): void
    {
        $this->countTokens($event);

        foreach (BuildLogEvents::arrayAt(BuildLogEvents::arrayAt($event, 'message'), 'content') as $block) {
            if (is_array($block)) {
                $this->takeBlock($block);
            }
        }
    }

    public function reading(): BuildLogReading
    {
        return new BuildLogReading(
            max($this->resultTokens, array_sum($this->perMessage)),
            hash('sha256', implode("\0", $this->changes)),
            $this->failing,
            in_array(true, $this->open, true),
        );
    }

    /**
     * @param array<mixed> $event
     */
    private function countTokens(array $event): void
    {
        $type = BuildLogEvents::textAt($event, 'type');
        $message = BuildLogEvents::arrayAt($event, 'message');

        if ('result' === $type) {
            $this->resultTokens = max($this->resultTokens, BuildLogEvents::tokensIn($event['usage'] ?? null));
        }

        if ('assistant' === $type) {
            // Keyed by the message id, because one message arrives once for each of its content blocks with the same usage.
            $id = BuildLogEvents::textAt($message, 'id');
            $this->perMessage['' !== $id ? $id : 'line-'.count($this->perMessage)] = BuildLogEvents::tokensIn($message['usage'] ?? null);
        }
    }

    /**
     * @param array<mixed> $block
     */
    private function takeBlock(array $block): void
    {
        'tool_use' === BuildLogEvents::textAt($block, 'type') ? $this->startCall($block) : $this->endCall($block);
    }

    /**
     * @param array<mixed> $block
     */
    private function startCall(array $block): void
    {
        $id = BuildLogEvents::textAt($block, 'id');

        if ('' === $id) {
            return;
        }

        $tool = BuildLogEvents::textAt($block, 'name');
        $input = BuildLogEvents::arrayAt($block, 'input');
        $this->open[$id] = BuildLogEvents::runsTests($tool, BuildLogEvents::textAt($input, 'command'));
        $change = BuildLogEvents::changeBy($tool, $input);

        if ('' !== $change) {
            $this->changes[] = $change;
        }
    }

    /**
     * @param array<mixed> $block
     */
    private function endCall(array $block): void
    {
        $id = BuildLogEvents::textAt($block, 'tool_use_id');

        if ('tool_result' !== BuildLogEvents::textAt($block, 'type') || !isset($this->open[$id])) {
            return;
        }

        if ($this->open[$id]) {
            $this->failing = BuildLogEvents::failuresIn(BuildLogEvents::textOf($block['content'] ?? null));
        }
        unset($this->open[$id]);
    }
}
