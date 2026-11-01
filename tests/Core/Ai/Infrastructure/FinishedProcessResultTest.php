<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Infrastructure\FinishedProcessResult;
use Intakt\Tests\Plugin\SymfonyAiVendor\RecordedClaudeCodeCli;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\ClaudeCode\ClaudeCode;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RuntimeException;

#[CoversClass(FinishedProcessResult::class)]
final class FinishedProcessResultTest extends TestCase
{
    public function testACompleteStreamGivesItsFinalResult(): void
    {
        $data = $this->finished(RecordedClaudeCodeCli::replaying('complete'))->getData();

        self::assertSame('result', $data['type']);
    }

    public function testTheToolCallsTheCliAlreadyRanAreNotHandedOn(): void
    {
        $data = $this->finished(RecordedClaudeCodeCli::replaying('tool-use'))->getData();

        self::assertSame('result', $data['type']);
        self::assertArrayNotHasKey('tool_calls', $data);
    }

    public function testAStreamThatEndsWithoutItsFinalResultIsCutOff(): void
    {
        try {
            $this->finished(RecordedClaudeCodeCli::replaying('truncated'))->getData();
            self::fail('A stream cut off before its final result must not pass as complete.');
        } catch (IncompleteStreamException $cutOff) {
            self::assertStringContainsString('ended before its final result: the stream ended.', $cutOff->getMessage());
        }
    }

    public function testACliThatExitsMidStreamIsCutOff(): void
    {
        try {
            $this->finished(RecordedClaudeCodeCli::exitingAfter('truncated', 143))->getData();
            self::fail('A stream cut off before its final result must not pass as complete.');
        } catch (IncompleteStreamException $cutOff) {
            self::assertStringContainsString('exit code 143', $cutOff->getMessage());
        }
    }

    public function testACliKilledMidStreamIsCutOff(): void
    {
        try {
            $this->finished(RecordedClaudeCodeCli::killedAfter('truncated'))->getData();
            self::fail('A stream cut off before its final result must not pass as complete.');
        } catch (IncompleteStreamException $cutOff) {
            self::assertStringContainsString('the CLI was stopped by signal 15', $cutOff->getMessage());
        }
    }

    public function testACliThatTimesOutMidStreamIsCutOff(): void
    {
        try {
            $this->finished(RecordedClaudeCodeCli::stalledAfter('truncated'))->getData();
            self::fail('A stream cut off before its final result must not pass as complete.');
        } catch (IncompleteStreamException $cutOff) {
            self::assertStringContainsString('the CLI timed out', $cutOff->getMessage());
        }
    }

    public function testACliThatFailsAfterItsFinalResultStaysAFailure(): void
    {
        try {
            $this->finished(RecordedClaudeCodeCli::exitingAfter('failed', 1))->getData();
        } catch (RuntimeException $failure) {
            self::assertNotInstanceOf(IncompleteStreamException::class, $failure);
            self::assertStringContainsString('Not logged in', $failure->getMessage());

            return;
        }

        self::fail('A failed CLI run gave a result.');
    }

    private function finished(RecordedClaudeCodeCli $cli): FinishedProcessResult
    {
        return new FinishedProcessResult($cli->request(new ClaudeCode('sonnet'), 'Review.'));
    }
}
