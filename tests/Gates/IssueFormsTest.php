<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class IssueFormsTest extends TestCase
{
    public function testBlankIssuesAreOffAndTheOnlyLinkIsPrivateReporting(): void
    {
        self::assertMatchesRegularExpression('#\Ablank_issues_enabled: false\ncontact_links:\n  - name: Report a security problem\n    url: https://github\.com/shinyredapples/intakt/security/advisories/new\n    about: .+\n\z#', $this->read('ISSUE_TEMPLATE/config.yml'));
    }

    public function testEachFormAsksForItsFieldsLinksTheGuideAndAppliesNoLabel(): void
    {
        foreach (['bug_report' => 'what-happened|what-you-expected|steps|version', 'feature_request' => 'what-should-be-different|how-to-tell-it-is-done'] as $form => $ids) {
            $text = $this->read('ISSUE_TEMPLATE/'.$form.'.yml');
            preg_match_all('/^    id: (\S+)$/m', $text, $found);

            self::assertSame($ids, implode('|', $found[1]));
            self::assertMatchesRegularExpression('#docs/guide/writing-a-good-task\.md#', $text);
            self::assertDoesNotMatchRegularExpression('/^labels:/m', $text);
        }
    }

    public function testThePullRequestTemplateLinksTheReviewAndHasItsSections(): void
    {
        self::assertMatchesRegularExpression('/CONTRIBUTING\.md#how-your-issue-or-pull-request-is-handled.*^Closes #.*^## Summary$.*^## Evidence$/ms', $this->read('pull_request_template.md'));
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(__DIR__.'/../../.github/'.$path);
    }
}
