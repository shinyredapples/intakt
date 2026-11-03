<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\ServerRequest;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\FileSessionLock;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;

// One request of the probe in its own process, so the requests of one session really overlap: php worker.php <scratch dir> <lock|nolock> <request file>.
require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $dir, $mode, $requestFile] = $argv;
$request = json_decode((string) file_get_contents($requestFile), true, flags: JSON_THROW_ON_ERROR);

final class ProbeTools
{
    public function __construct(private readonly string $dir, private readonly string $mode)
    {
    }

    public function slow(RequestContext $context): string
    {
        $id = (string) $context->getRequest()->getId();
        $this->log('enter '.$id);

        // Without the lock all three requests are inside the tool at once before any leaves it; with the lock the others cannot get in, so the tool only lingers.
        'nolock' === $this->mode ? $this->waitForAllThree() : usleep(200_000);

        $context->getClientGateway()->progress(1, 2, 'half');
        $context->getClientGateway()->progress(2, 2, 'done');
        $this->log('exit '.$id);

        return 'done '.$id;
    }

    private function log(string $line): void
    {
        file_put_contents($this->dir.'/log', $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function waitForAllThree(): void
    {
        $deadline = hrtime(true) + 10_000_000_000;

        while (3 > substr_count((string) file_get_contents($this->dir.'/log'), 'enter')) {
            if (hrtime(true) > $deadline) {
                return;
            }

            usleep(5_000);
        }
    }
}

$builder = Server::builder()
    ->setServerInfo('probe', '1.0.0')
    ->setSession(new FileSessionStore($dir.'/sessions'))
    ->addTool([new ProbeTools($dir, $mode), 'slow'], 'slow', description: 'Reports progress twice, takes no parameters');

if ('lock' === $mode) {
    $builder->setSessionLock(new FileSessionLock($dir.'/locks', 30.0));
}

$http = new ServerRequest('POST', 'http://localhost/mcp', $request['headers'], $request['body']);

// A streamed answer is flushed between events, so a collecting callback keeps what a plain buffer would pass on.
$streamed = '';
ob_start(static function (string $chunk) use (&$streamed): string {
    $streamed .= $chunk;

    return '';
});
$response = $builder->build()->run(new StreamableHttpTransport($http));
$body = (string) $response->getBody();
ob_end_clean();
$body = $streamed.$body;

echo json_encode([
    'status' => $response->getStatusCode(),
    'session' => $response->getHeaderLine('Mcp-Session-Id'),
    'body' => $body,
], JSON_THROW_ON_ERROR);
