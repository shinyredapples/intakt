<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Contract;

use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Core\Review\Contract\GlobCannotBeEvaluated;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeIdEmpty;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Contract\RepositoryNotFound;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

// Covers no code, because which failures carry the interface is a declaration rather than behavior.
#[CoversNothing]
final class ReportableFailureTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function expectedFailures(): iterable
    {
        yield 'change not found' => [ChangeNotFound::named(new ChangeId('feature'))];
        yield 'change id empty' => [new ChangeIdEmpty('A change id is not empty.')];
        yield 'forge read failed' => [new ForgeReadFailed('git failed.')];
        yield 'panel not configured' => [new PanelNotConfigured('alpha')];
        yield 'glob cannot be evaluated' => [new GlobCannotBeEvaluated('src/**', 'too deep')];
        yield 'round not next' => [RoundNotNext::for(3, 1)];
        yield 'repository not found' => [RepositoryNotFound::at('missing')];
        yield 'repository attributes refused' => [RepositoryAttributesRefused::at('info/attributes')];
        yield 'job event not recorded' => [JobEventNotRecorded::for('job_started', 'disk full')];
    }

    #[DataProvider('expectedFailures')]
    public function testAnExpectedFailureIsReportedToThePersonRunningTheCommand(Throwable $failure): void
    {
        self::assertInstanceOf(ReportableFailure::class, $failure);
    }
}
