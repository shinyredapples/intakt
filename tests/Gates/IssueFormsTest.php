<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
final class IssueFormsTest extends TestCase
{
    private const string FOLDER = __DIR__.'/../../.github/';

    public function testBlankIssuesAreOffAndTheOnlyContactLinkIsPrivateVulnerabilityReporting(): void
    {
        $config = $this->yaml('ISSUE_TEMPLATE/config.yml');

        self::assertFalse($config['blank_issues_enabled'] ?? null);
        self::assertSame([['name' => 'Report a security problem', 'url' => 'https://github.com/shinyredapples/intakt/security/advisories/new']], array_map(static fn (array $link): array => array_intersect_key($link, ['name' => 0, 'url' => 0]), $config['contact_links'] ?? []));
    }

    /** @param list<string> $ids */
    #[DataProvider('forms')]
    public function testAFormAsksForItsFieldsLinksTheGuideAndAppliesNoLabelButTriage(string $file, array $ids): void
    {
        $form = $this->yaml('ISSUE_TEMPLATE/'.$file);
        $body = $form['body'] ?? [];

        self::assertSame($ids, array_values(array_filter(array_column($body, 'id'))));
        self::assertStringContainsString('docs/guide/writing-a-good-task.md', implode("\n", array_column(array_column($body, 'attributes'), 'value')));
        // A label from a template never steers Intakt (only people with write access do), so any other label would mislead its author.
        self::assertSame([], array_diff((array) ($form['labels'] ?? []), ['triage']));
    }

    public function testThePullRequestTemplateHoldsSummaryEvidenceAClosesLineAndHowItIsReviewed(): void
    {
        $template = (string) file_get_contents(self::FOLDER.'pull_request_template.md');

        self::assertMatchesRegularExpression('/^Closes #.*^## Summary$.*^## Evidence$/ms', $template);
        self::assertStringContainsString('CONTRIBUTING.md#how-your-issue-or-pull-request-is-handled', $template);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function forms(): array
    {
        return [
            'bug report' => ['bug_report.yml', ['what-happened', 'what-you-expected', 'steps', 'version']],
            'feature request' => ['feature_request.yml', ['what-should-be-different', 'how-to-tell-it-is-done']],
        ];
    }

    /** @return array{blank_issues_enabled?: bool, contact_links?: list<array<string, string>>, labels?: string|list<string>, body?: list<array{id?: string, attributes?: array{value?: string}}>} */
    private function yaml(string $path): array
    {
        /** @var array{blank_issues_enabled?: bool, contact_links?: list<array<string, string>>, labels?: string|list<string>, body?: list<array{id?: string, attributes?: array{value?: string}}>} $yaml */
        $yaml = Yaml::parseFile(self::FOLDER.$path);

        return $yaml;
    }
}
