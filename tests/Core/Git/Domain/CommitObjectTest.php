<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\Domain;

use Intakt\Core\Git\Domain\CommitObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommitObject::class)]
final class CommitObjectTest extends TestCase
{
    private const string PLAIN = "tree aaa\nparent bbb\nauthor A <a@example.invalid> 1 +0000\ncommitter A <a@example.invalid> 1 +0000\n\nSubject\n\nBody\n";

    public function testACommitReadsBackAsItWasGiven(): void
    {
        self::assertSame(self::PLAIN, CommitObject::of(self::PLAIN)->text());
    }

    public function testASignatureIsAHeaderWhoseValueContinuesOnIndentedLines(): void
    {
        $signed = CommitObject::of(self::PLAIN)->signedWith("-----BEGIN SSH SIGNATURE-----\nabc\n-----END SSH SIGNATURE-----\n", 'gpgsig');

        self::assertStringContainsString("\ngpgsig -----BEGIN SSH SIGNATURE-----\n abc\n -----END SSH SIGNATURE-----\n\nSubject", $signed->text());
        self::assertSame("-----BEGIN SSH SIGNATURE-----\nabc\n-----END SSH SIGNATURE-----\n", $signed->signature('gpgsig'));
    }

    public function testThePayloadIsTheCommitWithoutItsSignature(): void
    {
        $signed = CommitObject::of(self::PLAIN)->signedWith("-----BEGIN SSH SIGNATURE-----\nabc\n-----END SSH SIGNATURE-----\n", 'gpgsig');

        self::assertSame(self::PLAIN, $signed->payload());
    }

    public function testACommitWithoutASignatureHasNone(): void
    {
        self::assertNull(CommitObject::of(self::PLAIN)->signature('gpgsig'));
    }

    public function testSigningAgainReplacesTheEarlierSignatureOfEitherHeader(): void
    {
        $once = CommitObject::of(self::PLAIN)->signedWith("first\n", 'gpgsig-sha256');
        $twice = $once->signedWith("second\n", 'gpgsig');

        self::assertNull($twice->signature('gpgsig-sha256'));
        self::assertSame("second\n", $twice->signature('gpgsig'));
    }

    public function testAParentIsMovedOnlyWhenItWasReplaced(): void
    {
        $moved = CommitObject::of(self::PLAIN)->reparented(['bbb' => 'ccc']);
        $kept = CommitObject::of(self::PLAIN)->reparented(['zzz' => 'ccc']);

        self::assertStringContainsString("\nparent ccc\n", $moved->text());
        self::assertSame(self::PLAIN, $kept->text());
    }
}
