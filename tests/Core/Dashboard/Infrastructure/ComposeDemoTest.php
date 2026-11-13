<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

// Covers nothing because compose.yaml and .env.dist are files Docker Compose reads, not classes.
#[CoversNothing]
final class ComposeDemoTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../../..';

    public function testComposeReadsTheEnvFileTheBuildContextLeavesOut(): void
    {
        $envFiles = array_column($this->service()['env_file'], 'path');

        self::assertSame(['.env.local'], $envFiles);
        self::assertContains('.env.local', explode(PHP_EOL, new Filesystem()->readFile(self::ROOT.'/.dockerignore')));
    }

    public function testTheSeededFilesLiveOnTheDataVolume(): void
    {
        $service = $this->service();

        self::assertSame(['intakt-data:/app/var/data'], $service['volumes']);
        self::assertStringStartsWith('/app/var/data/', $service['environment']['INTAKT_CONFIG']);
        self::assertStringStartsWith('/app/var/data/', $service['environment']['INTAKT_SAMPLE_REPOSITORY']);
    }

    public function testTheServiceNamesItselfSoThePrintedReviewCommandRunsFromTheHost(): void
    {
        /** @var array{services: array<string, array{environment: array<string, string>}>} $compose */
        $compose = Yaml::parseFile(self::ROOT.'/compose.yaml');

        foreach ($compose['services'] as $name => $service) {
            self::assertSame($name, $service['environment']['INTAKT_COMPOSE_SERVICE']);
        }
    }

    public function testTheDashboardIsPublishedOnLoopbackAtTheImagesDashboardPort(): void
    {
        self::assertMatchesRegularExpression('/^\s+DASHBOARD_PORT=7781\b/m', new Filesystem()->readFile(self::ROOT.'/Dockerfile'));
        self::assertSame(['127.0.0.1:7781:7781'], $this->service()['ports']);
    }

    public function testTheEnvTemplateListsTheKeysTheSeedAcceptsWithoutValues(): void
    {
        $template = $this->envTemplate();

        foreach ([...Provider::Anthropic->variables(), ...Provider::OpenAi->variables(), SampleProject::HUGGING_FACE_TOKEN] as $key) {
            self::assertArrayHasKey($key, $template);
            self::assertSame('', $template[$key]);
        }
    }

    public function testTheEnvTemplateListsEverySecretTheEntrypointRequiresWithoutValues(): void
    {
        preg_match_all('/^: "\$\{(\w+):\?/m', new Filesystem()->readFile(self::ROOT.'/docker/entrypoint.sh'), $required);
        $template = $this->envTemplate();

        // The image sets the issuer, so only the two secrets are the owner's to fill in.
        foreach (array_diff($required[1], ['MERCURE_JWT_ISSUER', 'INTAKT_CONFIG']) as $secret) {
            self::assertArrayHasKey($secret, $template);
            self::assertSame('', $template[$secret]);
        }
    }

    /**
     * @return array{env_file: list<array{path: string, required: bool}>, environment: array<string, string>, ports: list<string>, volumes: list<string>}
     */
    private function service(): array
    {
        /** @var array{services: array{intakt: array{env_file: list<array{path: string, required: bool}>, environment: array<string, string>, ports: list<string>, volumes: list<string>}}} $compose */
        $compose = Yaml::parseFile(self::ROOT.'/compose.yaml');

        return $compose['services']['intakt'];
    }

    /**
     * @return array<string, string>
     */
    private function envTemplate(): array
    {
        /** @var array<string, string> $variables */
        $variables = new Dotenv()->parse(new Filesystem()->readFile(self::ROOT.'/.env.dist'));

        return $variables;
    }
}
