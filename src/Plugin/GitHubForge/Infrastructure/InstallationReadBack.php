<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use NoDiscard;
use Psr\Log\LoggerInterface;

// A failed read stops the start, because trusting an installation nobody could inspect would hide a wrong identity.
final readonly class InstallationReadBack
{
    public function __construct(
        private InstallationReader $reader,
        private ExpectedInstallation $expected,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws InstallationNotReadBack
     * @throws InstallationDoesNotMatchConfiguration
     */
    #[NoDiscard('an installation that is read back and not looked at proves nothing')]
    public function check(): Installation
    {
        $installation = $this->readBack();
        $differences = $this->expected->differencesFrom($installation);
        if ([] !== $differences) {
            $this->logger->warning('The GitHub App installation does not match the configuration.', ['differences' => $differences]);

            throw InstallationDoesNotMatchConfiguration::with(...$differences);
        }

        return $installation;
    }

    private function readBack(): Installation
    {
        try {
            return $this->reader->read();
        } catch (InstallationNotReadBack $failure) {
            $this->logger->warning('The GitHub App installation was not read back.', ['exception' => $failure]);

            throw $failure;
        }
    }
}
