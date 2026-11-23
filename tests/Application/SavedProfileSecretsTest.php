<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Server\Infrastructure\PathOnlyProfilerStorage;
use Intakt\Core\Server\Infrastructure\ProfileOnlyRequestMeasuresPass;
use Intakt\Kernel;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpKernel\KernelInterface;

#[CoversClass(ProfileOnlyRequestMeasuresPass::class)]
#[CoversClass(PathOnlyProfilerStorage::class)]
#[UsesClass(Kernel::class)]
final class SavedProfileSecretsTest extends WebTestCase
{
    private const string ENVIRONMENT_CANARY = 'canary-environment-7f3a';

    private const string SERVER_CANARY = 'canary-server-91c2';

    private const string BEARER_CANARY = 'canary-bearer-4d8e';

    private const string SESSION_CANARY = 'canary-session-b6a1';

    private const string BODY_CANARY = 'canary-body-e05f';

    private const string QUERY_CANARY = 'canary-query-3c7b';

    public function testASavedProfileHoldsNoneOfTheSecretsOfItsRequest(): void
    {
        $_SERVER['INTAKT_PROFILE_CANARY'] = $_ENV['INTAKT_PROFILE_CANARY'] = self::ENVIRONMENT_CANARY;
        putenv('INTAKT_PROFILE_CANARY='.self::ENVIRONMENT_CANARY);
        $_SERVER['SERVER_PROFILE_CANARY'] = self::SERVER_CANARY;
        $client = self::createClient();
        $client->getCookieJar()->set(new Cookie('MOCKSESSID', self::SESSION_CANARY));
        $client->enableProfiler();

        $client->request('POST', '/mcp/review', ['note' => self::BODY_CANARY], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::BEARER_CANARY,
            'SERVER_PROFILE_CANARY' => self::SERVER_CANARY,
        ]);

        self::assertResponseStatusCodeSame(401);
        $saved = $this->savedProfile($client->getKernel(), (string) $client->getResponse()->headers->get('X-Debug-Token'));
        foreach ([self::ENVIRONMENT_CANARY, self::SERVER_CANARY, self::BEARER_CANARY, self::SESSION_CANARY, self::BODY_CANARY] as $canary) {
            self::assertStringNotContainsString($canary, $saved);
        }
    }

    public function testASavedProfileHoldsNeitherTheQueryNorTheClientAddressOfItsRequest(): void
    {
        $client = self::createClient();
        $client->enableProfiler();

        $client->request('POST', '/mcp/review?access_token='.self::QUERY_CANARY, [], [], ['REMOTE_ADDR' => '192.0.2.44']);

        self::assertResponseStatusCodeSame(401);
        $saved = $this->savedProfile($client->getKernel(), (string) $client->getResponse()->headers->get('X-Debug-Token'));
        self::assertStringNotContainsString(self::QUERY_CANARY, $saved);
        self::assertStringNotContainsString('192.0.2.44', $saved);
    }

    public function testARequestOfATestThatDoesNotAskForAProfileSavesNone(): void
    {
        $client = self::createClient();

        $client->request('GET', '/mcp/review');

        self::assertFalse($client->getResponse()->headers->has('X-Debug-Token'));
        self::assertEmpty($client->getProfile());
    }

    protected function tearDown(): void
    {
        unset($_SERVER['INTAKT_PROFILE_CANARY'], $_ENV['INTAKT_PROFILE_CANARY'], $_SERVER['SERVER_PROFILE_CANARY']);
        putenv('INTAKT_PROFILE_CANARY');
        parent::tearDown();
    }

    // Read as written to disk rather than through the profiler, because the rule is about what a saved profile file holds.
    private function savedProfile(KernelInterface $kernel, string $token): string
    {
        $file = sprintf('%s/profiler/%s/%s/%s', $kernel->getCacheDir(), substr($token, -2, 2), substr($token, -4, 2), $token);
        self::assertFileExists($file);
        $saved = gzdecode((string) file_get_contents($file));
        self::assertIsString($saved);

        return $saved;
    }
}
