<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Infrastructure\ReviewJobConfiguration;
use Intakt\Core\Review\Infrastructure\ReviewJobFailure;
use Intakt\Core\Review\Infrastructure\ReviewJobImages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(ReviewJobConfiguration::class)]
#[CoversClass(ReviewJobImages::class)]
#[CoversClass(ReviewJobFailure::class)]
final class ReviewJobConfigurationTest extends TestCase
{
    public function testAProjectsImageIsTheImageOfItsReviewJobs(): void
    {
        $images = new ReviewJobImages(ReviewJobConfiguration::imagesOf($this->processed(['image' => 'intakt-job/alpha:review'])));

        self::assertSame('intakt-job/alpha:review', $images->imageFor('alpha'));
    }

    public function testAProjectWithoutAReviewJobSectionHasNoImage(): void
    {
        $images = new ReviewJobImages(ReviewJobConfiguration::imagesOf($this->processed(['image' => 'intakt-job/alpha:review'])));

        $this->expectException(ReviewJobFailure::class);
        $this->expectExceptionMessageIsOrContains('The project "beta" has no review job image');

        $images->imageFor('beta');
    }

    public function testASectionWithoutAnImageIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/"image" under "intakt\.projects\.alpha" must be configured/');

        $this->processed([]);
    }

    public function testAnEmptyImageIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processed(['image' => '']);
    }

    public function testAnImageThatIsNoTextIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processed(['image' => ['intakt-job/alpha:review']]);
    }

    public function testAnUnknownKeyIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processed(['image' => 'intakt-job/alpha:review', 'imgae' => 'other']);
    }

    /**
     * @param array<string, mixed> $section
     *
     * @return array<string, array{image: string}>
     */
    private function processed(array $section): array
    {
        /** @var array{projects: array<string, array{image: string}>} $configuration */
        $configuration = new Processor()->processConfiguration(new ReviewJobConfiguration(), [['projects' => ['alpha' => $section]]]);

        return $configuration['projects'];
    }
}
