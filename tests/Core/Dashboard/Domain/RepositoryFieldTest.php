<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\RepositoryField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RepositoryField::class)]
final class RepositoryFieldTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function addresses(): iterable
    {
        yield 'an https address' => ['https://github.com/acme/field-notes', 'acme/field-notes'];
        yield 'an address ending in .git' => ['https://github.com/acme/field-notes.git', 'acme/field-notes'];
        yield 'an address with a trailing slash' => ['https://github.com/acme/field-notes/', 'acme/field-notes'];
        yield 'the www host' => ['https://www.github.com/acme/field-notes', 'acme/field-notes'];
        yield 'a host in capitals' => ['https://GitHub.com/acme/field-notes', 'acme/field-notes'];
        yield 'the owner/name form' => ['acme/field-notes', 'acme/field-notes'];
        yield 'spaces around it' => ['  acme/field-notes  ', 'acme/field-notes'];
        yield 'a name with dots and underscores' => ['acme/my_repo.js', 'acme/my_repo.js'];
        yield 'an owner of 39 characters' => [str_repeat('a', 39).'/x', str_repeat('a', 39).'/x'];
        yield 'an owner with an inner dash' => ['ac-me/x', 'ac-me/x'];
    }

    #[DataProvider('addresses')]
    public function testARepositoryAddressOnGitHubIsReadAsOwnerAndName(string $input, string $repository): void
    {
        $field = RepositoryField::submitted($input);

        self::assertSame($repository, $field->repository);
        self::assertNull($field->error);
        self::assertSame($input, $field->input);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'nothing' => ['', RepositoryField::EMPTY];
        yield 'only spaces' => ['   ', RepositoryField::EMPTY];
        yield 'another host' => ['https://gitlab.com/acme/field-notes', RepositoryField::NOT_GITHUB];
        yield 'a host that only starts like GitHub' => ['https://github.com.example/acme/x', RepositoryField::NOT_GITHUB];
        yield 'plain http' => ['http://github.com/acme/field-notes', RepositoryField::NOT_AN_ADDRESS];
        yield 'another scheme' => ['ssh://github.com/acme/field-notes', RepositoryField::NOT_AN_ADDRESS];
        yield 'a user in the address' => ['https://someone@github.com/acme/field-notes', RepositoryField::NOT_AN_ADDRESS];
        yield 'a port' => ['https://github.com:8443/acme/field-notes', RepositoryField::NOT_AN_ADDRESS];
        yield 'a query' => ['https://github.com/acme/field-notes?tab=issues', RepositoryField::NOT_AN_ADDRESS];
        yield 'a fragment' => ['https://github.com/acme/field-notes#readme', RepositoryField::NOT_AN_ADDRESS];
        yield 'a page below the repository' => ['https://github.com/acme/field-notes/issues', RepositoryField::NOT_AN_ADDRESS];
        yield 'only an owner' => ['https://github.com/acme', RepositoryField::NOT_AN_ADDRESS];
        yield 'an address that does not parse' => ['https://git hub.com/acme/x', RepositoryField::NOT_AN_ADDRESS];
        yield 'the host without a scheme' => ['github.com/acme/field-notes', RepositoryField::NOT_AN_ADDRESS];
        yield 'a word' => ['field-notes', RepositoryField::NOT_AN_ADDRESS];
        yield 'an owner of 40 characters' => [str_repeat('a', 40).'/x', RepositoryField::NOT_AN_ADDRESS];
        yield 'an owner starting with a dash' => ['-acme/x', RepositoryField::NOT_AN_ADDRESS];
        yield 'an owner ending with a dash' => ['acme-/x', RepositoryField::NOT_AN_ADDRESS];
        yield 'an owner with two dashes in a row' => ['ac--me/x', RepositoryField::NOT_AN_ADDRESS];
        yield 'a name of 101 characters' => ['acme/'.str_repeat('x', 101), RepositoryField::NOT_AN_ADDRESS];
        yield 'the parent folder as name' => ['acme/..', RepositoryField::NOT_AN_ADDRESS];
        yield 'only .git as name' => ['acme/.git', RepositoryField::NOT_AN_ADDRESS];
        yield 'a name with a slash' => ['acme/x/y', RepositoryField::NOT_AN_ADDRESS];
        yield 'markup' => ['<b>acme</b>/x', RepositoryField::NOT_AN_ADDRESS];
    }

    #[DataProvider('refusals')]
    public function testAnInputThatNamesNoGitHubRepositoryIsRefusedWithItsReason(string $input, string $error): void
    {
        $field = RepositoryField::submitted($input);

        self::assertNull($field->repository);
        self::assertSame($error, $field->error);
        self::assertTrue($field->wasChecked());
    }

    public function testAFieldThatWasNotSubmittedHasNoErrorAndNoRepository(): void
    {
        $field = RepositoryField::unsubmitted();

        self::assertSame(['', null, null], [$field->input, $field->repository, $field->error]);
        self::assertFalse($field->wasChecked());
    }
}
