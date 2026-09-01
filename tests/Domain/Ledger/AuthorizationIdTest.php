<?php

declare(strict_types=1);

namespace Ledger\Tests\Domain\Ledger;

use Ledger\Domain\Ledger\AuthorizationId;
use Ledger\Domain\Ledger\Exception\InvalidAuthorizationId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthorizationId::class)]
final class AuthorizationIdTest extends TestCase
{
    public function testCarriesItsValue(): void
    {
        self::assertSame('Auth-A', AuthorizationId::of('Auth-A')->value);
        self::assertSame('Auth-A', (string) AuthorizationId::of('Auth-A'));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('Auth-B', AuthorizationId::of("  Auth-B\n")->value);
    }

    /** @return iterable<string, array{string}> */
    public static function blankValues(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'newline' => ["\n"];
    }

    #[DataProvider('blankValues')]
    public function testRefusesABlankId(string $value): void
    {
        $this->expectException(InvalidAuthorizationId::class);

        AuthorizationId::of($value);
    }

    public function testComparesByValue(): void
    {
        self::assertTrue(AuthorizationId::of('Auth-A')->equals(AuthorizationId::of('Auth-A')));
        self::assertFalse(AuthorizationId::of('Auth-A')->equals(AuthorizationId::of('Auth-B')));
    }
}
