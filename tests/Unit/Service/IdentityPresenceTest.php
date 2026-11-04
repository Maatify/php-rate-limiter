<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\IdentityPresence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-03 / F-185-04 (#185): an identity string (`accountId`, `correlationId`, `fingerprintHash`) is present when it is
 * a non-empty string, `"0"` included.
 */
#[Group('f-185-03')]
#[Group('f-185-04')]
final class IdentityPresenceTest extends TestCase
{
    /** @return iterable<string, array{?string, bool}> */
    public static function values(): iterable
    {
        yield 'null' => [null, false];
        yield 'empty' => ['', false];
        yield '"0"' => ['0', true];
        yield '"00"' => ['00', true];
        yield '"1"' => ['1', true];
        yield 'space' => [' ', true];
        yield 'owner' => ['owner', true];
    }

    #[DataProvider('values')]
    public function testPresenceIsANonEmptyString(?string $value, bool $present): void
    {
        $context = new RateLimitContextDTO('198.51.100.7', 'ua', $value);

        self::assertSame($present, IdentityPresence::isPresent($value));
        self::assertSame($present, IdentityPresence::account($context));
        self::assertSame($present ? $value : null, IdentityPresence::accountId($context));
        self::assertSame($present, IdentityPresence::fingerprint(new DeviceIdentityDTO($value, 'LOW', false)));
    }

    /** @return iterable<string, array{?string, ?string, ?string}> */
    public static function subjects(): iterable
    {
        yield 'correlation present' => ['corr', '1', 'corr'];
        yield 'correlation "0" is present' => ['0', '1', '0'];
        yield 'empty correlation falls back to the account' => ['', '1', '1'];
        yield 'null correlation falls back to the account' => [null, '1', '1'];
        yield 'empty correlation and no account' => ['', null, null];
        yield 'empty correlation and an empty account' => ['', '', null];
        yield 'null correlation and an empty account' => [null, '', null];
        yield 'nothing' => [null, null, null];
    }

    #[DataProvider('subjects')]
    public function testTheSubjectIsThePresentCorrelationElseThePresentAccount(?string $correlationId, ?string $accountId, ?string $subject): void
    {
        $context = new RateLimitContextDTO('198.51.100.7', 'ua', $accountId, null, null, false, [], false, $correlationId);

        self::assertSame($subject, IdentityPresence::subject($context));
    }
}
