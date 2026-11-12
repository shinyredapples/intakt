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
        $this->expectExceptionObject(JobImageNotBuilt::composeUnsupported());

        Devcontainer::planFrom('{"dockerComposeFile": "compose.yaml", "service": "app"}');
    }

    public function testADevcontainerWithNeitherImageNorBuildIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::withoutImageOrBuild());

        Devcontainer::planFrom('{"name": "dev"}');
    }

    public function testAContextOutsideTheRepositoryIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::contextOutside('../..'));

        Devcontainer::planFrom('{"build": {"dockerfile": "Dockerfile", "context": "../.."}}');
    }

    public function testAContextThatStaysInsideTheRepositoryIsAllowed(): void
    {
        $plan = Devcontainer::planFrom('{"build": {"dockerfile": "../docker/Dockerfile", "context": "../docker"}}');

        self::assertSame('docker', $plan->tree);
        self::assertSame('Dockerfile', $plan->dockerfile);
    }

    public function testAnAbsoluteContextIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::contextOutside('/context'));

        Devcontainer::planFrom('{"build": {"dockerfile": "Dockerfile", "context": "/context"}}');
    }

    public function testADockerfileOutsideTheContextIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::dockerfileOutsideContext('../Dockerfile'));

        Devcontainer::planFrom('{"build": {"dockerfile": "../Dockerfile"}}');
    }

    public function testAnImageThatIsNoReferenceIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::notAnImage("php:8.5\nRUN id"));

        Devcontainer::planFrom('{"image": "php:8.5\nRUN id"}');
    }

    public function testAnImageWithARegistryPortAndDigestIsAllowed(): void
    {
        $image = 'localhost:5000/team/php:8.5@sha256:'.str_repeat('a', 64);

        self::assertSame(sprintf("FROM %s\n", $image), Devcontainer::planFrom(sprintf('{"image": "%s"}', $image))->generated);
    }

    public function testABrokenFileIsRefusedWithTheReason(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::notJson(Devcontainer::PATH, 'Syntax error'));

        Devcontainer::planFrom('{"image": ');
    }

    public function testATopLevelThatIsNoObjectIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::notJson(Devcontainer::PATH, 'not an object'));

        Devcontainer::planFrom('["php"]');
    }
}
