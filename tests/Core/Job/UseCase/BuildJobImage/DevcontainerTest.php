<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\UseCase\BuildJobImage\Devcontainer;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImageNotBuilt;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImagePlan;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImageSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Devcontainer::class)]
#[UsesClass(JobImageNotBuilt::class)]
#[UsesClass(JobImagePlan::class)]
final class DevcontainerTest extends TestCase
{
    public function testAnImageIsTheBaseOfAOneLineDockerfile(): void
    {
        $plan = Devcontainer::planFrom('{"image": "ghcr.io/example/php:8.5"}');

        self::assertSame(JobImageSource::Devcontainer, $plan->source);
        self::assertSame("FROM ghcr.io/example/php:8.5\n", $plan->generated);
    }

    public function testABuildIsReadRelativeToTheDevcontainerFolder(): void
    {
        $plan = Devcontainer::planFrom('{"build": {"dockerfile": "Dockerfile", "context": ".."}}');

        self::assertSame('', $plan->tree);
        self::assertSame('.devcontainer/Dockerfile', $plan->dockerfile);
    }

    public function testABuildWithoutContextUsesTheDevcontainerFolder(): void
    {
        $plan = Devcontainer::planFrom('{"build": {"dockerfile": "php.Dockerfile"}}');

        self::assertSame('.devcontainer', $plan->tree);
        self::assertSame('php.Dockerfile', $plan->dockerfile);
    }

    public function testCommentsAndTrailingCommasAreAllowedOutsideStrings(): void
    {
        $jsonc = <<<'JSONC'
            // The dev environment.
            {
                /* the image, with "quotes" in a comment */
                "name": "a // b /* c */ \" d",
                "image": "php:8.5-cli", // trailing comment
                "features": {"x": [1, 2,],},
            }
            JSONC;

        self::assertSame("FROM php:8.5-cli\n", Devcontainer::planFrom($jsonc)->generated);
    }

    public function testACommaInsideAStringStays(): void
    {
        $plan = Devcontainer::planFrom('{"build": {"dockerfile": "a,}.Dockerfile"}}');

        self::assertSame('a,}.Dockerfile', $plan->dockerfile);
    }

    public function testAComposeSetupIsRefused(): void
    {
        $this->expectExceptionMessage('.devcontainer/devcontainer.json uses dockerComposeFile, which Intakt does not build a job image from yet');

        Devcontainer::planFrom('{"dockerComposeFile": "compose.yaml", "service": "app"}');
    }

    public function testADevcontainerWithNeitherImageNorBuildIsRefused(): void
    {
        $this->expectExceptionMessage('.devcontainer/devcontainer.json names neither an image nor a build with a dockerfile');

        Devcontainer::planFrom('{"name": "dev"}');
    }

    public function testAContextOutsideTheRepositoryIsRefused(): void
    {
        $this->expectExceptionMessage('The build context "../.." of .devcontainer/devcontainer.json is outside the repository');

        Devcontainer::planFrom('{"build": {"dockerfile": "Dockerfile", "context": "../.."}}');
    }

    public function testAnAbsoluteContextIsRefused(): void
    {
        $this->expectExceptionMessage('The build context "/srv" of .devcontainer/devcontainer.json is outside the repository');

        Devcontainer::planFrom('{"build": {"dockerfile": "Dockerfile", "context": "/srv"}}');
    }

    public function testADockerfileOutsideTheContextIsRefused(): void
    {
        $this->expectExceptionMessage('The dockerfile "../Dockerfile" of .devcontainer/devcontainer.json is outside its build context');

        Devcontainer::planFrom('{"build": {"dockerfile": "../Dockerfile"}}');
    }

    public function testAnImageThatIsNoReferenceIsRefused(): void
    {
        $this->expectExceptionMessage("The image \"php:8.5\nRUN id\" of .devcontainer/devcontainer.json is not an image reference");

        Devcontainer::planFrom('{"image": "php:8.5\nRUN id"}');
    }

    public function testABrokenFileIsRefusedWithTheReason(): void
    {
        $this->expectExceptionMessage('.devcontainer/devcontainer.json is not valid JSON: Syntax error');

        Devcontainer::planFrom('{"image": ');
    }

    public function testATopLevelThatIsNoObjectIsRefused(): void
    {
        $this->expectExceptionMessage('.devcontainer/devcontainer.json is not valid JSON: not an object');

        Devcontainer::planFrom('["php"]');
    }
}
