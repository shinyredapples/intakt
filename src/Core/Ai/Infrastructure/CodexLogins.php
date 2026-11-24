<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AiServices;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

// Each account's login is kept here and only here; a call gets a read-only copy in a home of its own, so nothing a call writes reaches the kept login.
final readonly class CodexLogins
{
    private const string LOGIN_FILE = 'auth.json';

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/codex')]
        private string $directory,
        private CodexTokenRefresh $refresh,
        private LockFactory $locks,
    ) {
    }

    // Under the account's lock, because a refresh replaces the refresh token and a second refresh with the old one would lose the login.
    public function handOver(string $account): string
    {
        $this->assertAccountName($account);
        $lock = $this->locks->createLock(AiServices::CODEX_LOGIN_LOCK_PREFIX.$account);
        $lock->acquire(true);

        try {
            $this->copyReadOnly($account, $this->refreshedIfDue($account));
        } finally {
            $lock->release();
        }

        return $this->callHome($account);
    }

    public function loginFile(string $account): string
    {
        $this->assertAccountName($account);

        return $this->directory.'/logins/'.$account.'/'.self::LOGIN_FILE;
    }

    public function hasLogin(string $account): bool
    {
        return is_file($this->loginFile($account));
    }

    // The name becomes a path and a lock key, so only a name an account can have is accepted.
    private function assertAccountName(string $account): void
    {
        if (1 !== preg_match(Account::NAME_PATTERN, $account)) {
            throw new CodexLoginUnusable('"'.$account.'" is no account name.');
        }
    }

    private function refreshedIfDue(string $account): CodexLogin
    {
        $login = $this->stored($account);
        $refreshed = $this->refresh->refreshedIfDue($login);

        if (!$refreshed instanceof CodexLogin) {
            return $login;
        }

        $this->write($this->loginFile($account), $refreshed->json(), 0o600);

        return $refreshed;
    }

    private function stored(string $account): CodexLogin
    {
        $file = $this->loginFile($account);
        $json = is_file($file) ? file_get_contents($file) : false;

        if (false === $json) {
            throw new CodexLoginUnusable('Account "'.$account.'" has no Codex login: copy its auth.json to '.$file.'.');
        }

        return CodexLogin::fromJson($json);
    }

    private function copyReadOnly(string $account, CodexLogin $login): void
    {
        $this->write($this->callHome($account).'/'.self::LOGIN_FILE, $login->json(), 0o400);
    }

    private function callHome(string $account): string
    {
        return $this->directory.'/homes/'.$account;
    }

    // The call can write its home, so a link it leaves for the home or the file must not redirect this write: a link home is removed, and a new file renamed over the path replaces a link instead of following it.
    private function write(string $file, string $contents, int $mode): void
    {
        $filesystem = new Filesystem();
        $directory = dirname($file);

        if (is_link($directory)) {
            $filesystem->remove($directory);
        }

        $filesystem->mkdir($directory, 0o700);
        $written = $filesystem->tempnam($directory, '.'.self::LOGIN_FILE.'.');
        $filesystem->appendToFile($written, $contents);
        $filesystem->chmod($written, $mode);
        $filesystem->rename($written, $file, true);
    }
}
