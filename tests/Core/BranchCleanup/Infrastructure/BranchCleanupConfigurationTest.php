<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\BranchCleanup\Infrastructure;

use Intakt\Core\BranchCleanup\Infrastructure\BranchCleanupConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(BranchCleanupConfiguration::class)]
final class BranchCleanupConfigurationTest extends TestCase
{
    public function testAProjectThatSetsNothingCleansOnlyIntaktsBranchesAndKeepsNoneByName(): void
    {
        self::assertSame(['widgets' => ['clean_other_branches' => false, 'keep_branches' => []]], $this->processed(['widgets' => []]));
    }

    public function testAProjectMayOptInAndNameTheBranchesItKeeps(): void
    {
        self::assertSame(
            ['widgets' => ['clean_other_branches' => true, 'keep_branches' => ['experiments', 'intakt/7-demo']]],
            $this->processed(['widgets' => ['clean_other_branches' => true, 'keep_branches' => ['experiments', 'intakt/7-demo']]]),
        );
    }

    public function testAnEmptyBranchNameInKeepBranchesIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processed(['widgets' => ['keep_branches' => ['']]]);
    }

    public function testAnOptInThatIsNoBooleanIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processed(['widgets' => ['clean_other_branches' => 'yes']]);
    }

    /**
     * @param array<string, array<string, mixed>> $projects
     *
     * @return array<mixed>
     */
    private function processed(array $projects): array
    {
        $processed = new Processor()->processConfiguration(new BranchCleanupConfiguration(), [['projects' => $projects]])['projects'];
        self::assertIsArray($processed);

        return $processed;
    }
}
