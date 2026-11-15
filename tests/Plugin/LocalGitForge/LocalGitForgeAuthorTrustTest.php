<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Plugin\Forge\Contract\BinaryFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalGitForge::class)]
#[UsesClass(BinaryFiles::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(LocalRepository::class)]
final class LocalGitForgeAuthorTrustTest extends TestCase
{
    public function testTheAuthorOfALocalBranchIsTrustedWithoutAskingGit(): void
    {
        $forge = new LocalGitForge(new LocalRepository(__DIR__.'/no-repository-here', 'main'), new NameStatusParser());

        self::assertTrue($forge->isAuthorTrusted(new ChangeId('feature/from-a-stranger')));
    }
}
