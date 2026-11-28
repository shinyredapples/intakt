<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Taken in the order the CLI wrote the events, because a result only closes the call that came before it; kept across a watch's looks, so each look reads only what is new.
#[Exclude]
final class BuildLogTally
{
    /** @var array<string, int> */
    private array $perMessage = [];

    private int $messageTokens = 0;

    private int $resultTokens = 0;

    /** @var list<string> */
    private array $changes = [];

    /** @var array<string, array{test: bool, commit: bool, background: bool, shell: string, kill: bool}> */
    private array $open = [];

    // Test runs that went on in the background, by the id of their shell; the empty id stands for one whose result named none.
    /** @var array<string, true> */
    private array $background = [];

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
            max($this->resultTokens, $this->messageTokens),
            hash('sha256', implode("\0", $this->changes)),
            $this->failing,
            [] !== $this->background || in_array(true, array_column($this->open, 'test'), true),
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
            // Keyed by the message id, because one message arrives once for each of its content blocks with the same usage; only ever raised, so a line with the same id and a lower figure never lowers it.
            $id = BuildLogEvents::textAt($message, 'id');
            $key = '' !== $id ? $id : 'line-'.count($this->perMessage);
            $seen = $this->perMessage[$key] ?? 0;
            $tokens = max($seen, BuildLogEvents::tokensIn($message['usage'] ?? null));
            $this->perMessage[$key] = $tokens;
            $this->messageTokens = BuildLogEvents::plus($this->messageTokens, $tokens - $seen);
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
        $command = BuildLogEvents::textAt($input, 'command');
        $test = BuildLogEvents::runsTests($tool, $command);

        if ($test) {
            // A new test run tells that the one whose shell was never named is over.
            unset($this->background['']);
        }

        $this->open[$id] = [
            'test' => $test,
            'commit' => BuildLogEvents::triesToCommit($tool, $command),
            'background' => true === ($input['run_in_background'] ?? null),
            'shell' => BuildLogEvents::shellLookedAt($input),
            'kill' => str_starts_with($tool, 'Kill'),
        ];
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
        $call = $this->open[$id] ?? null;

        if ('tool_result' !== BuildLogEvents::textAt($block, 'type') || null === $call) {
            return;
        }

        unset($this->open[$id]);
        $text = BuildLogEvents::textOf($block['content'] ?? null);
        $commit = $call['commit'] && true !== ($block['is_error'] ?? null) ? BuildLogEvents::commitIn($text) : '';

        if ('' !== $commit) {
            $this->changes[] = 'commit:'.$commit;
        }

        if ($call['test']) {
            $this->endTestRun($text, $call['background']);
        }

        if (isset($this->background[$call['shell']]) && ($call['kill'] || BuildLogEvents::showsEnded($text))) {
            unset($this->background[$call['shell']]);
            $this->failing = $call['kill'] ? $this->failing : BuildLogEvents::failuresIn($text);
        }
    }

    // A run that went on in the background is still running, so it stays a running check until its shell shows it ended.
    private function endTestRun(string $text, bool $sentToBackground): void
    {
        $shell = BuildLogEvents::backgroundShellIn($text) ?? ($sentToBackground ? '' : null);

        if (null === $shell) {
            $this->failing = BuildLogEvents::failuresIn($text);

            return;
        }

        $this->background[$shell] = true;
    }
}
