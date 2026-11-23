<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Infrastructure;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Watchdog\Contract\CommitHistory;
use Intakt\Core\Watchdog\Contract\MainHistoryRewritten;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\RecordedMerge;
use Intakt\Core\Watchdog\Contract\SignatureStatus;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

final readonly class MainHistory implements CommitHistory
{
    // One limit for every git and ssh-keygen call, because a hung call must not hold the watchdog forever.
    public const float TIMEOUT_SECONDS = 60;

    // The full ref, because a tag or a remote branch named main must not stand in for the branch.
    private const string MAIN = 'refs/heads/main';

    // One header per object format, because git names the signature header after the hash the repository uses.
    private const array SIGNATURE_HEADERS = ['sha1' => 'gpgsig', 'sha256' => 'gpgsig-sha256'];

    private const array NAME_LENGTHS = ['sha1' => 40, 'sha256' => 64];

    // An empty shallow file is git's own way to read no shallow entries, so git follows the real parents and fails on one that is missing.
    private const array NO_SHALLOW_ENTRIES = ['--shallow-file', ''];

    private TrustedSignatures $signatures;

    /**
     * @param list<string> $ownerKeys
     * @param list<string> $peerKeys
     *
     * @throws OwnerKeyRefused
     */
    public function __construct(
        private HardenedGit $git,
        private string $repository,
        array $ownerKeys,
        Filesystem $filesystem,
        array $peerKeys = [],
        // Main's head read once for a whole check, because every read of one check must see the same main.
        private ?string $pinnedTip = null,
    ) {
        $this->signatures = new TrustedSignatures($ownerKeys, $peerKeys, $filesystem);
    }

    /**
     * @throws MainNotRead
     * @throws RuntimeException
     */
    public function contains(string $commit): bool
    {
        $this->mustBeReadable();

        $contained = $this->holds(['merge-base', '--is-ancestor', $commit, $this->tip()]);
        // Checked again for a "no", because a repository cut shallow after the first check lacks the commits that would answer yes.
        if (!$contained) {
            $this->mustBeComplete();
        }

        return $contained;
    }

    /**
     * @return list<string>
     *
     * @throws MainNotRead
     * @throws RuntimeException
     * @throws MainHistoryRewritten
     */
    public function firstParentsSince(string $commit): array
    {
        $this->mustBeReadable();
        // One pinned tip for the ancestry check and the walk, because a force push between two reads of the ref would walk a history that dropped the commit.
        $tip = $this->tip();
        if (!$this->holds(['merge-base', '--is-ancestor', $commit, $tip])) {
            // Checked again, because a repository cut shallow after the first check would read as a force push.
            $this->mustBeComplete();

            throw MainHistoryRewritten::dropped($commit, $tip);
        }

        // The full id, because the walk's own parent ids are compared with it.
        $since = trim($this->read(['rev-parse', '--verify', $commit.'^{commit}']));
        $walk = trim($this->read(['rev-list', '--first-parent', '--reverse', '--parents', $since.'..'.$tip]));

        $chain = FirstParentChain::fromWalk('' === $walk ? [] : explode("\n", $walk), $since, $tip);
        if (null === $chain->foot) {
            return [];
        }

        // The foot must be an ancestor of the commit (a cut can hide ancestry but never invent it, so a yes is true), and the oldest commit must not be one, asked with no shallow entries, because a cut during the walk can hide that main already held it; a pruned commit then fails the read instead of answering no.
        if (!$this->holds(['merge-base', '--is-ancestor', $chain->foot, $since]) || $this->holds(['merge-base', '--is-ancestor', $chain->commits[0], $since], self::NO_SHALLOW_ENTRIES)) {
            throw MainNotRead::brokenWalk($since, $tip);
        }

        return $chain->commits;
    }

    /**
     * @throws MainNotRead
     * @throws RuntimeException
     * @throws IOException
     */
    public function signatureOf(string $commit): SignatureStatus
    {
        $this->mustBeReadable();
        $header = self::SIGNATURE_HEADERS[trim($this->read(['rev-parse', '--show-object-format']))] ?? null;
        // A format git adds later has a header this class does not know, so its commits fail closed instead of reading as unsigned.
        if (null === $header) {
            return SignatureStatus::UnknownKey;
        }

        $signed = $this->split($this->read(['cat-file', 'commit', $commit]), $header);
        if (null === $signed) {
            return SignatureStatus::Unsigned;
        }

        return $this->signatures->signerOf(...$signed);
    }

    /**
     * @throws MainNotRead
     * @throws RuntimeException
     */
    public function head(): string
    {
        $this->mustBeReadable();

        return $this->tip();
    }

    /**
     * @throws MainNotRead
     * @throws RuntimeException
     */
    public function commitNameLength(): int
    {
        $this->mustBeReadable();
        $format = trim($this->read(['rev-parse', '--show-object-format']));

        return self::NAME_LENGTHS[$format] ?? throw MainNotRead::because(sprintf('the repository uses the object format "%s", which the watchdog does not know', $format));
    }

    /**
     * @return list<string>
     *
     * @throws MainNotRead
     * @throws RuntimeException
     */
    public function broughtInBy(RecordedMerge $merge): array
    {
        $this->mustBeReadable();
        // Read with no shallow entries, because a cut during the read would hide what the base already held and pass it as brought in; a pruned commit then fails the read. The trailing -- keeps a file named like the base from making it ambiguous.
        $brought = trim($this->read(['rev-list', $merge->head, '--not', $merge->base, '--'], self::NO_SHALLOW_ENTRIES));

        return '' === $brought ? [] : explode("\n", $brought);
    }

    /**
     * @throws MainNotRead
     * @throws RuntimeException
     */
    private function mustBeReadable(): void
    {
        $this->mustBeTheRepository();
        $this->mustBeComplete();
    }

    /**
     * @throws MainNotRead
     * @throws RuntimeException
     */
    private function mustBeTheRepository(): void
    {
        $directory = $this->directory();
        $bare = 'true' === $this->discovered(['rev-parse', '--is-bare-repository']);
        $gitDirectory = $this->discovered(['rev-parse', '--absolute-git-dir']);
        // A bare repository is named by its git directory; anything else must be the top of its work tree, so a folder inside a work tree, or its .git, is refused.
        $found = $bare ? $gitDirectory : $this->discovered(['rev-parse', '--show-toplevel']);
        if ($found !== $directory) {
            throw MainNotRead::notTheRepository($directory, $found);
        }

        // The enclosing repository's core.worktree can name any folder as its top, so the folder must also hold the git directory git found.
        if (!$bare && $gitDirectory !== $this->ownGitDirectory($directory)) {
            throw MainNotRead::notTheRepository($directory, $gitDirectory);
        }
    }

    /**
     * @throws MainNotRead
     * @throws RuntimeException
     */
    private function tip(): string
    {
        // show-ref --verify takes the exact ref only, because rev-parse would fall back to a tag named refs/heads/main once the branch is gone.
        return $this->pinnedTip ?? trim($this->read(['show-ref', '--verify', '--hash', self::MAIN]));
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $options
     *
     * @throws MainNotRead
     * @throws RuntimeException
     */
    private function holds(array $arguments, array $options = []): bool
    {
        $answer = $this->run($arguments, options: $options);

        return match ($answer->getExitCode()) {
            0 => true,
            1 => false,
            // Any other exit is a failed read, never an answer, because "not contained" would report a force push that did not happen.
            default => throw new ProcessFailedException($answer),
        };
    }

    /**
     * @throws MainNotRead
     * @throws RuntimeException
     */
    private function mustBeComplete(): void
    {
        $answer = $this->run(['rev-parse', '--is-shallow-repository']);
        // A failed answer is refused too, because damaged shallow metadata makes git exit before it prints.
        if (!$answer->isSuccessful() || 'false' !== trim($answer->getOutput())) {
            throw MainNotRead::shallow($this->directory());
        }
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $options
     *
     * @throws MainNotRead
     * @throws RuntimeException
     */
    private function read(array $arguments, array $options = []): string
    {
        $answer = $this->run($arguments, options: $options);

        return $answer->isSuccessful() ? $answer->getOutput() : throw new ProcessFailedException($answer);
    }

    /**
     * @throws MainNotRead
     */
    private function directory(): string
    {
        // Resolved here, because a relative path would otherwise be read against wherever the process happens to run.
        $directory = realpath($this->repository);

        // A file is no repository, because git cannot run inside it.
        return false === $directory || !is_dir($directory) ? throw MainNotRead::missing($this->repository) : $directory;
    }

    /**
     * @param list<string> $arguments
     *
     * @throws MainNotRead
     * @throws RuntimeException
     */
    private function discovered(array $arguments): string
    {
        // Without the pinned work tree, because HardenedGit names the folder itself as the work tree, and git would then call any folder inside a repository its top.
        $answer = $this->run($arguments, ['GIT_WORK_TREE' => false]);
        if (!$answer->isSuccessful()) {
            throw MainNotRead::notTheRepository($this->directory(), '');
        }

        return $this->withoutLineBreak($answer->getOutput());
    }

    /**
     * @throws MainNotRead
     * @throws RuntimeException
     */
    private function ownGitDirectory(string $directory): string
    {
        // Asked of git, because a linked work tree or a submodule has a .git file that names its git directory, and only git reads that file the way git does (a path in it may hold a line break).
        $answer = $this->run(['rev-parse', '--resolve-git-dir', $directory.'/.git'], ['GIT_WORK_TREE' => false]);
        $resolved = $answer->isSuccessful() ? realpath($this->withoutLineBreak($answer->getOutput())) : false;

        return false === $resolved ? '' : $resolved;
    }

    /**
     * @param list<string>                $arguments
     * @param array<string, string|false> $overrides
     * @param list<string>                $options
     *
     * @throws MainNotRead
     * @throws RuntimeException
     */
    private function run(array $arguments, array $overrides = [], array $options = []): Process
    {
        $directory = $this->directory();

        try {
            $call = $this->git->call($arguments, $directory);
        } catch (RepositoryAttributesRefused $refused) {
            throw MainNotRead::because($refused->getMessage());
        } catch (RuntimeException $failed) {
            // Because HardenedGit first asks git for the repository's object format, and that call can be stopped or fail to start.
            throw MainNotRead::because(sprintf('git %s could not be prepared: %s', $arguments[0], $failed->getMessage()));
        }

        // Options go right after the program, because git reads its global options only before the command.
        $process = new Process([$call->command[0], ...$options, ...array_slice($call->command, 1)], $call->directory, [...$call->environment, ...$overrides], null, self::TIMEOUT_SECONDS);
        $process->run();

        return $process;
    }

    // Only git's one line break is removed, because a folder name may end in a space.
    private function withoutLineBreak(string $output): string
    {
        return str_ends_with($output, "\n") ? substr($output, 0, -1) : $output;
    }

    // Only the lines up to the first empty line, because a signature header in the message is not a header.
    /**
     * @return array{payload: string, signature: string}|null
     */
    private function split(string $object, string $header): ?array
    {
        $end = strpos($object, "\n\n");
        $headers = explode("\n", false === $end ? $object : substr($object, 0, $end));
        $message = false === $end ? '' : substr($object, $end);
        $signatureLines = $this->signatureLines($headers, $header);
        // A second signature header makes the signature ambiguous, so the commit gets an empty signature that no key verifies.
        if (null === $signatureLines) {
            return ['payload' => '', 'signature' => ''];
        }
        if ([] === $signatureLines) {
            return null;
        }

        $signature = array_map(static fn (int $index): string => substr($headers[$index], 1), $signatureLines);
        $signature[0] = substr($headers[$signatureLines[0]], strlen($header) + 1);

        return ['payload' => implode("\n", array_diff_key($headers, array_flip($signatureLines))).$message, 'signature' => implode("\n", $signature)."\n"];
    }

    /**
     * @param list<string> $headers
     *
     * @return list<int>|null
     */
    private function signatureLines(array $headers, string $header): ?array
    {
        $starts = array_keys(array_filter($headers, static fn (string $line): bool => array_any(self::SIGNATURE_HEADERS, static fn (string $name): bool => str_starts_with($line, $name.' '))));
        // A second header, or the other format's header, refuses the commit, because a commit carrying both could pass on a signature that covers the other header.
        if (count($starts) > 1 || ([] !== $starts && !str_starts_with($headers[$starts[0]], $header.' '))) {
            return null;
        }
        if ([] === $starts) {
            return [];
        }

        $lines = [$starts[0]];
        for ($index = $starts[0] + 1; isset($headers[$index]) && str_starts_with($headers[$index], ' '); ++$index) {
            $lines[] = $index;
        }

        return $lines;
    }
}
