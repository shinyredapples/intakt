<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Contract\HandBackRefusal;
use Intakt\Core\Platform\Contract\PlatformServices;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

// gitleaks with its built-in rules over every object the bundle adds and over the pull request text, because the build controls both and so any scanner settings among them.
final readonly class BundleSecretScan
{
    private const int FOUND = 3;
    private const int TIMEOUT_SECONDS = 600;
    // Emptied, so a variable set for another tool cannot swap the built-in rules for weaker ones.
    private const array ENVIRONMENT = ['GITLEAKS_CONFIG' => false, 'GITLEAKS_CONFIG_TOML' => false];

    public function __construct(
        #[Autowire(env: 'default:'.PlatformServices::GITLEAKS.':INTAKT_GITLEAKS')]
        private string $scanner,
    ) {
    }

    /**
     * @throws HandBackNotChecked
     */
    public function refusalOf(GivenHistory $history, string $head, JobCredentials $credentials): ?HandBackRefusal
    {
        $contents = $history->newObjectContents($head);

        // The commits were redacted already, so a credential found here is in a file or a file name, which is the build's work and is not rewritten.
        if ($this->holdsAny($contents, $credentials)) {
            return HandBackRefusal::HoldsCredential;
        }

        return $this->findsSecretIn($contents, $history->repository) ? HandBackRefusal::HoldsSecret : null;
    }

    /**
     * @throws HandBackNotChecked
     */
    public function refusalOfText(string $text, string $repository, JobCredentials $credentials): ?HandBackRefusal
    {
        // Refused, not redacted, because the builder wrote the text and can write it again without the value.
        if ($this->holdsAny($text, $credentials)) {
            return HandBackRefusal::TextHoldsCredential;
        }

        return $this->findsSecretIn($text, $repository) ? HandBackRefusal::TextHoldsSecret : null;
    }

    private function holdsAny(string $contents, JobCredentials $credentials): bool
    {
        return array_any([...$credentials], static fn (string $credential): bool => str_contains($contents, $credential));
    }

    /**
     * @throws HandBackNotChecked
     */
    private function findsSecretIn(string $contents, string $repository): bool
    {
        // Read from standard input, so no file the build committed is on disk where gitleaks looks for settings or an ignore list; allow comments are ignored for the same reason.
        $process = new Process(
            [$this->scanner, 'stdin', '--no-banner', '--ignore-gitleaks-allow', '--redact', '--log-level', 'error', '--exit-code', (string) self::FOUND],
            $repository,
            self::ENVIRONMENT,
            $contents,
            self::TIMEOUT_SECONDS,
        );

        try {
            $process->run();
        } catch (ExceptionInterface) {
            throw HandBackNotChecked::in($repository, 'the secret scan could not run');
        }

        return match ($process->getExitCode()) {
            0 => false,
            self::FOUND => true,
            default => throw HandBackNotChecked::in($repository, 'the secret scan failed'),
        };
    }
}
