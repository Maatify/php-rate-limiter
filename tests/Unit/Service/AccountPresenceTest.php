<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\AccountPresence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** F-185-03 (#185): an account is present when its identifier is a non-empty string, `"0"` included. */
#[Group('f-185-03')]
final class AccountPresenceTest extends TestCase
{
    /** @return iterable<string, array{?string, bool}> */
    public static function identifiers(): iterable
    {
        yield 'null' => [null, false];
        yield 'empty' => ['', false];
        yield '"0"' => ['0', true];
        yield '"00"' => ['00', true];
        yield '"1"' => ['1', true];
        yield 'space' => [' ', true];
        yield 'owner' => ['owner', true];
    }

    #[DataProvider('identifiers')]
    public function testPresenceIsANonEmptyString(?string $accountId, bool $present): void
    {
        $context = new RateLimitContextDTO('198.51.100.7', 'ua', $accountId);

        self::assertSame($present, AccountPresence::isPresent($accountId));
        self::assertSame($present, AccountPresence::of($context));
        self::assertSame($present ? $accountId : null, AccountPresence::idOf($context));
    }
}
