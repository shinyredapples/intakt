<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

// Lines of the CLI's stream-json log, written as the CLI writes them, so the tests read the same shapes as a real build.
final class BuildLogLines
{
    public static function usage(string $id, int $tokens): string
    {
        return self::line(['type' => 'assistant', 'message' => ['id' => $id, 'usage' => ['input_tokens' => $tokens, 'output_tokens' => 0], 'content' => []]]);
    }

    public static function edit(string $callId, string $before, string $after): string
    {
        return self::tool($callId, 'Edit', ['old_string' => $before, 'new_string' => $after]);
    }

    public static function command(string $callId, string $command): string
    {
        return self::tool($callId, 'Bash', ['command' => $command]);
    }

    public static function result(string $callId, string $text): string
    {
        return self::line(['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => $callId, 'content' => $text]]]]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function tool(string $callId, string $name, array $input): string
    {
        return self::line(['type' => 'assistant', 'message' => ['id' => 'm-'.$callId, 'content' => [['type' => 'tool_use', 'id' => $callId, 'name' => $name, 'input' => $input]]]]);
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function line(array $event): string
    {
        return json_encode($event, JSON_THROW_ON_ERROR)."\n";
    }
}
