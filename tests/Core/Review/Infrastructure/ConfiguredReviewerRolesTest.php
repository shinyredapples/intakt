<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Review\Infrastructure\ConfiguredReviewerRoles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfiguredReviewerRoles::class)]
final class ConfiguredReviewerRolesTest extends TestCase
{
    public function testAProviderServesEachRoleOnceAcrossProjectsAndRules(): void
    {
        $roles = new ConfiguredReviewerRoles([
            'one' => [
                'reviewers' => [['role' => 'correctness', 'provider' => 'anthropic'], ['role' => 'style', 'provider' => 'codex']],
                'rules' => [['reviewers' => [['role' => 'security', 'provider' => 'anthropic']]]],
            ],
            'two' => ['reviewers' => [['role' => 'correctness', 'provider' => 'anthropic']]],
        ]);

        self::assertSame(['correctness', 'security'], $roles->servedBy(Provider::Anthropic));
        self::assertSame(['style'], $roles->servedBy(Provider::Codex));
    }

    public function testAProviderNoPanelUsesServesNoRole(): void
    {
        $roles = new ConfiguredReviewerRoles(['one' => ['reviewers' => [['role' => 'correctness', 'provider' => 'anthropic']]]]);

        self::assertSame([], $roles->servedBy(Provider::OpenAi));
    }
}
