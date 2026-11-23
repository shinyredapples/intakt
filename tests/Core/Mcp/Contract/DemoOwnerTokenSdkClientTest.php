<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Contract;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleProject;
use Intakt\Core\Review\UseCase\SeedSampleProject\SampleRepository;
use Intakt\Kernel;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Mcp\Contract\Tool\OnePanel;
use Intakt\Tests\Support\KernelTestCase;
use Mcp\Client;
use Mcp\Client\Builder;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// Covers nothing because what is proven is the demo's chain: the token docker/entrypoint.sh makes, the line the start prints with it once the server answers, and an MCP client that connects with that line's token.
#[Group('persistence')]
#[CoversNothing]
final class DemoOwnerTokenSdkClientTest extends KernelTestCase
{
    private const array VARIABLES = ['MCP_OWNER_TOKEN', 'MCP_OWNER_ACCESS'];

    private string $directory;

    /** @var array<string, string> */
    private array $previous = [];

    public function testThePrintedTokenOfAFirstStartListsTheToolsAndStartsAReview(): void
    {
        $client = $this->connectedWith($this->printedToken($this->tokenTheFirstStartMade()));

        self::assertContains('start_review', array_map(static fn (Tool $tool): string => $tool->name, $client->listTools()->tools));
        $result = $client->callTool('start_review', ['review' => [
            'project' => 'alpha',
            'task' => SampleProject::TASK,
            'change' => SampleRepository::BRANCH,
            'repository' => $this->directory.'/sample-repository',
            'base' => SampleRepository::BASE,
        ]]);
        self::assertFalse($result->isError);
        self::assertIsArray($result->structuredContent);
        self::assertSame(1, $result->structuredContent['round']);
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-demo-owner-token-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);

        foreach (self::VARIABLES as $name) {
            $current = $_SERVER[$name] ?? '';
            $this->previous[$name] = is_string($current) ? $current : '';
        }
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);

        // Put back what .env set, because later tests of the process resolve the same variables.
        foreach ($this->previous as $name => $value) {
            $this->setVariable($name, $value);
        }

        parent::tearDown();
    }

    // The entrypoint runs with a stand-in php whose seed step fails, so the start ends right after it has made the token, without a server.
    private function tokenTheFirstStartMade(): string
    {
        $programs = $this->directory.'/stubs';
        new Filesystem()->dumpFile($programs.'/php', "#!/bin/bash\ncase \"\$*\" in *intakt:sample:seed*) exit 1;; esac\nexit 0\n");
        new Filesystem()->chmod($programs.'/php', 0755);

        $start = new Process(['docker/entrypoint.sh'], dirname(__DIR__, 4), [
            'PATH' => $programs.':'.getenv('PATH'),
            'APP_SECRET' => 'demo-owner-token-test-secret',
            'MERCURE_JWT_SECRET' => 'demo-owner-token-test-secret',
            'MERCURE_JWT_ISSUER' => 'http://localhost',
            'MCP_OWNER_TOKEN' => false,
            'MCP_OWNER_ACCESS' => false,
            // Cleared, because the start removes the folders they name, which a caller's setting would point outside this test.
            'APP_CACHE_DIR' => false,
            'APP_BUILD_DIR' => false,
            'INTAKT_SAMPLE_REPOSITORY' => $this->directory.'/sample-repository',
            'INTAKT_CONFIG' => $this->directory.'/intakt.yaml',
            'MCP_OWNER_KEPT_FILE' => $this->directory.'/mcp-owner-token',
        ]);
        $start->run();

        return trim(new Filesystem()->readFile($this->directory.'/mcp-owner-token'));
    }

    // The demo's own settings, as the entrypoint exports them; the project file is kept, so the seed needs no provider key.
    private function printedToken(string $made): string
    {
        $this->setVariable('MCP_OWNER_TOKEN', $made);
        $this->setVariable('MCP_OWNER_ACCESS', 'mcp_tool_write');
        $kernel = self::bootKernel();
        new Filesystem()->dumpFile($this->directory.'/intakt.yaml', "projects: {}\n");

        $seed = new CommandTester(new Application($kernel)->find('intakt:sample:seed'));
        $seed->execute(['repository' => $this->directory.'/sample-repository', 'configuration' => $this->directory.'/intakt.yaml']);
        $show = new CommandTester(new Application($kernel)->find('intakt:sample:show'));
        $show->execute(['repository' => $this->directory.'/sample-repository']);

        preg_match('/^Connect an MCP client: .* --header "Authorization: Bearer (\S+)"$/m', $show->getDisplay(), $line);

        return $line[1] ?? self::fail('The start printed no MCP setup: '.$show->getDisplay());
    }

    private function connectedWith(string $bearer): Client
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(Kernel::class, $kernel);
        $container = self::getContainer();
        $entities = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in($container);
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $container->set(ChangeReviews::class, $container->get('intakt.test.mcp.review_change'));
        $container->set(Panels::class, $container->get(OnePanel::class));

        $client = new Builder()->setProtocolVersion(ProtocolVersion::V2025_11_25)->build();
        $client->connect(new HttpTransport('http://localhost/mcp/owner', ['Authorization' => 'Bearer '.$bearer], new KernelHttpClient($kernel)));

        return $client;
    }

    private function setVariable(string $name, string $value): void
    {
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }
}
