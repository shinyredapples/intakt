<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class ShowcaseFiguresTest extends SandboxTestCase
{
    private const string SCRIPT = __DIR__.'/../../docs/showcase/figures.sh';

    public function testAFindingShapedLineInsideAFencedBlockIsNotCounted(): void
    {
        $sandbox = $this->emptySandbox();
        $this->writeFakeGh($sandbox);

        $result = $sandbox->runScript(
            'PATH="$PWD/bin:$PATH" bash '.escapeshellarg(self::SCRIPT).' example/repo',
        );

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertStringContainsString('| Findings (P1 / P2 / P3) | 1 (0 / 1 / 0) |', $result->output);
    }

    private function writeFakeGh(Sandbox $sandbox): void
    {
        $sandbox->write('prs.json', json_encode([[
            'number' => 7,
            'author' => ['login' => 'app/intakt-runner'],
            'mergedBy' => ['login' => 'app/intakt-runner'],
            'createdAt' => '2026-10-01T10:00:00Z',
            'mergedAt' => '2026-10-01T11:00:00Z',
            'labels' => [],
            'closingIssuesReferences' => [['number' => 3]],
            'reviews' => [],
        ]], JSON_THROW_ON_ERROR));
        $sandbox->write('comments.json', json_encode([[
            'issue_url' => 'https://api.example.test/repos/example/repo/issues/7',
            'user' => ['login' => 'intakt-runner[bot]'],
            'body' => "**Review by Claude**\n\n1. [P2] A real finding outside a fence.\n\n```\n2. [P1] Quoted example inside a fence.\n```\n",
        ]], JSON_THROW_ON_ERROR));
        $sandbox->write('bin/gh', "#!/usr/bin/env bash\nif [ \"\$1\" = pr ]; then cat \"\$PWD/prs.json\"; else jq -c '.[]' \"\$PWD/comments.json\"; fi\n");
        chmod($sandbox->root.'/bin/gh', 0o755);
    }
}
