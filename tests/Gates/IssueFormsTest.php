<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

// A label from a template never steers Intakt (only people with write access do), so a form that set one would mislead its author.
#[CoversNothing]
final class IssueFormsTest extends TestCase
{
    private const string TEMPLATES = __DIR__.'/../../.github/ISSUE_TEMPLATE';
    private const string PULL_REQUEST_TEMPLATE = __DIR__.'/../../.github/pull_request_template.md';
    private const string GUIDE = 'docs/guide/writing-a-good-task.md';
    private const string ALLOWED_LABEL = 'triage';

    public function testBlankIssuesAreTurnedOff(): void
    {
        self::assertFalse($this->config()['blank_issues_enabled'] ?? null, '.github/ISSUE_TEMPLATE/config.yml must set blank_issues_enabled: false.');
    }

    public function testTheOnlyContactLinkPointsToPrivateVulnerabilityReporting(): void
    {
        $links = $this->config()['contact_links'] ?? [];

        self::assertCount(1, $links, '.github/ISSUE_TEMPLATE/config.yml must hold exactly one contact link.');
        self::assertSame('Report a security problem', $links[0]['name'] ?? null);
        self::assertMatchesRegularExpression('#^https://github\.com/[^/]+/[^/]+/security/advisories/new$#', $links[0]['url'] ?? '');
    }

    public function testThereAreABugReportAndAFeatureRequestForm(): void
    {
        self::assertSame(['bug_report.yml', 'feature_request.yml'], array_keys(self::forms()));
    }

    #[DataProvider('forms')]
    public function testAFormAppliesNoLabelButTriage(string $path): void
    {
        $labels = (array) ($this->form($path)['labels'] ?? []);

        self::assertSame([], array_diff($labels, [self::ALLOWED_LABEL]), 'An issue form may apply only the neutral label triage.');
    }

    #[DataProvider('forms')]
    public function testAFormLinksTheGuideToWritingAGoodTask(string $path): void
    {
        $markdown = implode("\n", array_map(static fn (array $field): string => $field['attributes']['value'] ?? '', $this->form($path)['body']));

        self::assertStringContainsString(self::GUIDE, $markdown);
    }

    #[DataProvider('forms')]
    public function testEveryInputOfAFormHasAUniqueId(string $path): void
    {
        $inputs = array_filter($this->form($path)['body'], static fn (array $field): bool => 'markdown' !== $field['type']);
        $ids = array_values(array_map(static fn (array $field): string => $field['id'] ?? '', $inputs));

        self::assertNotContains('', $ids, 'Every input of an issue form needs an id.');
        self::assertSame($ids, array_values(array_unique($ids)));
    }

    public function testThePullRequestTemplateHoldsSummaryEvidenceAndAClosesLine(): void
    {
        $template = (string) file_get_contents(self::PULL_REQUEST_TEMPLATE);

        self::assertMatchesRegularExpression('/^## Summary$/m', $template);
        self::assertMatchesRegularExpression('/^## Evidence$/m', $template);
        self::assertMatchesRegularExpression('/^Closes #/m', $template);
        self::assertStringContainsString('CONTRIBUTING.md#how-your-issue-or-pull-request-is-handled', $template);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forms(): array
    {
        $forms = [];
        $paths = glob(self::TEMPLATES.'/*.yml');
        if (false === $paths) {
            return $forms;
        }

        foreach ($paths as $path) {
            if ('config.yml' !== basename($path)) {
                $forms[basename($path)] = [$path];
            }
        }

        return $forms;
    }

    /**
     * @return array{labels?: string|list<string>, body: list<array{type: string, id?: string, attributes?: array{value?: string}}>}
     */
    private function form(string $path): array
    {
        /** @var array{labels?: string|list<string>, body: list<array{type: string, id?: string, attributes?: array{value?: string}}>} $form */
        $form = Yaml::parseFile($path);

        return $form;
    }

    /**
     * @return array{blank_issues_enabled?: bool, contact_links?: list<array{name?: string, url?: string}>}
     */
    private function config(): array
    {
        /** @var array{blank_issues_enabled?: bool, contact_links?: list<array{name?: string, url?: string}>} $config */
        $config = Yaml::parseFile(self::TEMPLATES.'/config.yml');

        return $config;
    }
}
